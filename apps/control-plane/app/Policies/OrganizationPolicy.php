<?php

namespace App\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Models\Membership;
use App\Domains\Organizations\Models\Organization;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $this->membership($user, $organization) !== null;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->membership($user, $organization)?->role->canManageOrganization() ?? false;
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $this->membership($user, $organization)?->role->canManageMembers() ?? false;
    }

    public function manageApiTokens(User $user, Organization $organization): bool
    {
        return $this->membership($user, $organization)?->role->canManageInfrastructure() ?? false;
    }

    public function viewAudit(User $user, Organization $organization): bool
    {
        return $this->membership($user, $organization)?->role->canManageInfrastructure() ?? false;
    }

    private function membership(User $user, Organization $organization): ?Membership
    {
        return $organization->memberships()
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->first();
    }
}
