<?php

namespace App\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Enums\OrganizationRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Models\Project;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $this->role($user, $project->organization_id) !== null;
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->role($user, $organization->id)?->canDeploy() ?? false;
    }

    public function update(User $user, Project $project): bool
    {
        return $this->role($user, $project->organization_id)?->canDeploy() ?? false;
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->role($user, $project->organization_id)?->canManageInfrastructure() ?? false;
    }

    private function role(User $user, string $organizationId): ?OrganizationRole
    {
        return $user->memberships()
            ->where('organization_id', $organizationId)
            ->where('status', MembershipStatus::Active)
            ->first()
            ?->role;
    }
}
