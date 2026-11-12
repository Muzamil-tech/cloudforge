<?php

namespace App\Domains\Organizations\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Models\Invitation;
use App\Domains\Organizations\Models\Membership;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptInvitation
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(string $token, User $user): Organization
    {
        $invitation = Invitation::query()
            ->where('token_hash', Invitation::hashToken($token))
            ->first();

        if ($invitation === null) {
            throw ValidationException::withMessages([
                'token' => 'This invitation link is invalid.',
            ]);
        }

        if ($invitation->isAccepted()) {
            throw ValidationException::withMessages([
                'token' => 'This invitation has already been accepted.',
            ]);
        }

        if ($invitation->isExpired()) {
            throw ValidationException::withMessages([
                'token' => 'This invitation has expired.',
            ]);
        }

        if (strtolower($user->email) !== strtolower($invitation->email)) {
            throw ValidationException::withMessages([
                'token' => 'This invitation was issued to a different email address.',
            ]);
        }

        return DB::transaction(function () use ($invitation, $user): Organization {
            $membership = Membership::query()->updateOrCreate(
                [
                    'organization_id' => $invitation->organization_id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $invitation->role,
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                ],
            );

            $invitation->forceFill([
                'accepted_at' => now(),
                'accepted_by_user_id' => $user->id,
            ])->save();

            $this->auditLogger->record(
                AuditAction::MemberJoined,
                $invitation->organization,
                $membership,
                ['role' => $invitation->role->value],
                $user,
            );

            return $invitation->organization;
        });
    }

    public function pending(string $token): ?Invitation
    {
        return Invitation::query()
            ->where('token_hash', Invitation::hashToken($token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }
}
