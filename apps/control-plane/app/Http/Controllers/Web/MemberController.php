<?php

namespace App\Http\Controllers\Web;

use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Organizations\Actions\InviteMember;
use App\Domains\Organizations\Enums\OrganizationRole;
use App\Domains\Organizations\Models\Invitation;
use App\Domains\Organizations\Models\Membership;
use App\Http\Controllers\Controller;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function index(Request $request, OrganizationContext $organizationContext): Response
    {
        $organization = $organizationContext->require($request);
        Gate::authorize('view', $organization);

        $role = $organizationContext->role($request);

        return Inertia::render('Members/Index', [
            'members' => $organization->memberships()
                ->with('user')
                ->orderBy('joined_at')
                ->get()
                ->map(fn (Membership $membership): array => [
                    'id' => $membership->id,
                    'user_id' => $membership->user_id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'role' => $membership->role->value,
                    'role_label' => $membership->role->label(),
                    'status' => $membership->status->value,
                    'joined_at' => $membership->joined_at?->toIso8601String(),
                    'is_owner' => $membership->user_id === $organization->owner_user_id,
                    'is_self' => $membership->user_id === $request->user()->id,
                ])
                ->all(),
            'invitations' => $organization->invitations()
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->with('invitedBy')
                ->latest()
                ->get()
                ->map(fn (Invitation $invitation): array => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'role_label' => $invitation->role->label(),
                    'invited_by' => $invitation->invitedBy?->name ?? 'System',
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                    'created_at' => $invitation->created_at?->toIso8601String(),
                ])
                ->all(),
            'assignableRoles' => collect($role?->assignableRoles() ?? [])
                ->map(fn (OrganizationRole $assignable): array => [
                    'value' => $assignable->value,
                    'label' => $assignable->label(),
                ])
                ->all(),
            'canManage' => Gate::allows('manageMembers', $organization),
            'ownerUserId' => $organization->owner_user_id,
        ]);
    }

    public function invite(
        Request $request,
        OrganizationContext $organizationContext,
        InviteMember $inviteMember,
    ): RedirectResponse {
        $organization = $organizationContext->require($request);
        Gate::authorize('manageMembers', $organization);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in(array_column(OrganizationRole::cases(), 'value'))],
        ]);

        $result = $inviteMember->execute(
            $organization,
            $request->user(),
            $validated['email'],
            OrganizationRole::from($validated['role']),
        );

        return back()
            ->with('success', "Invitation created for {$validated['email']}.")
            ->with('invitation_url', route('register', ['invitation' => $result['token']]));
    }

    public function revokeInvitation(
        Request $request,
        Invitation $invitation,
        OrganizationContext $organizationContext,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $organization = $organizationContext->require($request);
        Gate::authorize('manageMembers', $organization);

        abort_unless($invitation->organization_id === $organization->id, 404);

        $invitation->delete();

        $auditLogger->record(
            AuditAction::InvitationRevoked,
            $organization,
            $invitation,
            ['email' => $invitation->email],
            $request->user(),
        );

        return back()->with('success', 'Invitation revoked.');
    }

    public function updateRole(
        Request $request,
        Membership $membership,
        OrganizationContext $organizationContext,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $organization = $organizationContext->require($request);
        Gate::authorize('manageMembers', $organization);

        abort_unless($membership->organization_id === $organization->id, 404);

        if ($membership->user_id === $organization->owner_user_id) {
            throw ValidationException::withMessages([
                'role' => 'The organization owner cannot be demoted.',
            ]);
        }

        if ($membership->user_id === $request->user()->id) {
            throw ValidationException::withMessages([
                'role' => 'You cannot change your own role.',
            ]);
        }

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(array_column(OrganizationRole::cases(), 'value'))],
        ]);

        $role = OrganizationRole::from($validated['role']);
        $actorRole = $organizationContext->role($request);

        if ($actorRole === null || ! in_array($role, $actorRole->assignableRoles(), true)) {
            throw ValidationException::withMessages([
                'role' => 'Your role cannot assign the requested role.',
            ]);
        }

        $previous = $membership->role;
        $membership->forceFill(['role' => $role])->save();

        $auditLogger->record(
            AuditAction::MemberRoleChanged,
            $organization,
            $membership,
            ['from' => $previous->value, 'to' => $role->value],
            $request->user(),
        );

        return back()->with('success', "{$membership->user->name} is now {$role->label()}.");
    }

    public function remove(
        Request $request,
        Membership $membership,
        OrganizationContext $organizationContext,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $organization = $organizationContext->require($request);
        Gate::authorize('manageMembers', $organization);

        abort_unless($membership->organization_id === $organization->id, 404);

        if ($membership->user_id === $organization->owner_user_id) {
            throw ValidationException::withMessages([
                'member' => 'The organization owner cannot be removed.',
            ]);
        }

        if ($membership->user_id === $request->user()->id) {
            throw ValidationException::withMessages([
                'member' => 'You cannot remove yourself. Transfer ownership or leave through support.',
            ]);
        }

        $name = $membership->user->name;

        $auditLogger->record(
            AuditAction::MemberRemoved,
            $organization,
            $membership,
            ['email' => $membership->user->email, 'role' => $membership->role->value],
            $request->user(),
        );

        $membership->delete();

        return back()->with('success', "{$name} was removed from the organization.");
    }
}
