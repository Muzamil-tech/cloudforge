<?php

namespace App\Domains\Organizations\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Organizations\Enums\OrganizationRole;
use App\Domains\Organizations\Models\Invitation;
use App\Domains\Organizations\Models\Membership;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InviteMember
{
    private const TTL_DAYS = 7;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @return array{invitation: Invitation, token: string}
     */
    public function execute(Organization $organization, User $actor, string $email, OrganizationRole $role): array
    {
        if (! in_array($role, $actor->memberships()->where('organization_id', $organization->id)->firstOrFail()->role->assignableRoles(), true)) {
            throw ValidationException::withMessages([
                'role' => 'Your role cannot assign the requested role.',
            ]);
        }

        $email = strtolower($email);

        $existingMember = Membership::query()
            ->where('organization_id', $organization->id)
            ->whereHas('user', fn ($query) => $query->whereRaw('lower(email) = ?', [$email]))
            ->first();

        if ($existingMember !== null) {
            throw ValidationException::withMessages([
                'email' => 'This person is already a member of the organization.',
            ]);
        }

        return DB::transaction(function () use ($organization, $actor, $email, $role): array {
            Invitation::query()
                ->where('organization_id', $organization->id)
                ->whereRaw('lower(email) = ?', [$email])
                ->whereNull('accepted_at')
                ->delete();

            $token = Invitation::generateToken();

            $invitation = Invitation::query()->create([
                'organization_id' => $organization->id,
                'email' => $email,
                'role' => $role,
                'token_hash' => Invitation::hashToken($token),
                'expires_at' => now()->addDays(self::TTL_DAYS),
                'invited_by_user_id' => $actor->id,
            ]);

            $this->auditLogger->record(
                AuditAction::MemberInvited,
                $organization,
                $invitation,
                ['email' => $email, 'role' => $role->value],
                $actor,
            );

            return ['invitation' => $invitation, 'token' => $token];
        });
    }
}
