<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Identity\Models\User;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Organizations\Actions\AcceptInvitation;
use App\Http\Controllers\Controller;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function create(Request $request, AcceptInvitation $acceptInvitation): Response
    {
        $token = $request->string('invitation')->toString();
        $invitation = $token === '' ? null : $acceptInvitation->pending($token);

        abort_unless($this->registrationOpen($invitation !== null), 403, 'Registration is closed.');

        return Inertia::render('Auth/Register', [
            'invitation' => $invitation === null ? null : [
                'token' => $token,
                'email' => $invitation->email,
                'role' => $invitation->role->label(),
                'organization' => $invitation->organization->name,
            ],
            'invitationToken' => $token === '' ? null : $token,
            'invitationValid' => $invitation !== null,
            'bootstrap' => User::query()->doesntExist(),
        ]);
    }

    public function store(
        Request $request,
        AcceptInvitation $acceptInvitation,
        OrganizationContext $organizationContext,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'invitation' => ['nullable', 'string'],
        ]);

        $email = strtolower($validated['email']);
        $token = $validated['invitation'] ?? null;
        $invitation = $token === null || $token === '' ? null : $acceptInvitation->pending($token);

        if ($token !== null && $token !== '' && $invitation === null) {
            throw ValidationException::withMessages([
                'invitation' => 'This invitation is invalid or has expired.',
            ]);
        }

        if ($invitation !== null && $invitation->email !== $email) {
            throw ValidationException::withMessages([
                'email' => 'This invitation was issued to a different email address.',
            ]);
        }

        if ($invitation === null && ! $this->registrationOpen(false)) {
            throw ValidationException::withMessages([
                'email' => 'Registration is closed. Ask an organization owner for an invitation.',
            ]);
        }

        /** @var User $user */
        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $email,
            'password' => $validated['password'],
        ]);

        $auditLogger->record(AuditAction::UserRegistered, null, $user, [], $user);

        Auth::login($user);
        $request->session()->regenerate();

        if ($invitation !== null) {
            $organization = $acceptInvitation->execute($token, $user);
            $organizationContext->set($request, $organization);

            return redirect()->route('dashboard')
                ->with('success', "You joined {$organization->name}.");
        }

        return redirect()->route('onboarding');
    }

    private function registrationOpen(bool $hasInvitation): bool
    {
        return $hasInvitation
            || (bool) config('cloudforge.registration.enabled')
            || User::query()->doesntExist();
    }
}
