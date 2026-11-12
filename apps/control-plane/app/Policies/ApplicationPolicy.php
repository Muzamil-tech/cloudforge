<?php

namespace App\Policies;

use App\Domains\Applications\Models\Application;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Enums\OrganizationRole;
use App\Domains\Projects\Models\Project;

class ApplicationPolicy
{
    public function view(User $user, Application $application): bool
    {
        return $this->role($user, $application->organization_id) !== null;
    }

    public function create(User $user, Project $project): bool
    {
        return $this->role($user, $project->organization_id)?->canDeploy() ?? false;
    }

    public function deploy(User $user, Application $application): bool
    {
        return $this->role($user, $application->organization_id)?->canDeploy() ?? false;
    }

    public function rollback(User $user, Application $application): bool
    {
        return $this->role($user, $application->organization_id)?->canDeploy() ?? false;
    }

    public function start(User $user, Application $application): bool
    {
        return $this->canOperate($user, $application->organization_id);
    }

    public function stop(User $user, Application $application): bool
    {
        return $this->canOperate($user, $application->organization_id);
    }

    public function refresh(User $user, Application $application): bool
    {
        return $this->canOperate($user, $application->organization_id);
    }

    public function delete(User $user, Application $application): bool
    {
        return $this->role($user, $application->organization_id)?->canDeploy() ?? false;
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
