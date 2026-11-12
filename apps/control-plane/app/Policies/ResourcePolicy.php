<?php

namespace App\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Enums\OrganizationRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Resources\Models\Resource;

class ResourcePolicy
{
    public function view(User $user, Resource $resource): bool
    {
        return $this->role($user, $resource->organization_id) !== null;
    }

    public function create(User $user, Project $project): bool
    {
        return $this->role($user, $project->organization_id)?->canDeploy() ?? false;
    }

    public function start(User $user, Resource $resource): bool
    {
        return $this->canOperate($user, $resource->organization_id);
    }

    public function stop(User $user, Resource $resource): bool
    {
        return $this->canOperate($user, $resource->organization_id);
    }

    public function refresh(User $user, Resource $resource): bool
    {
        return $this->canOperate($user, $resource->organization_id);
    }

    public function reveal(User $user, Resource $resource): bool
    {
        return $this->role($user, $resource->organization_id)?->canDeploy() ?? false;
    }

    public function recover(User $user, Resource $resource): bool
    {
        return $this->role($user, $resource->organization_id)?->canDeploy() ?? false;
    }

    public function delete(User $user, Resource $resource): bool
    {
        return $this->role($user, $resource->organization_id)?->canDeploy() ?? false;
    }

    private function role(User $user, string $organizationId): ?OrganizationRole
    {
        return $user->memberships()
            ->where('organization_id', $organizationId)
            ->where('status', MembershipStatus::Active)
            ->first()
            ?->role;
    }

    private function canOperate(User $user, string $organizationId): bool
    {
        $role = $this->role($user, $organizationId);

        return $role !== null && ($role->canOperate() || $role->canDeploy());
    }
}
