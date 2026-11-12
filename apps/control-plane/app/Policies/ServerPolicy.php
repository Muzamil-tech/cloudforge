<?php

namespace App\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Operations\Enums\AgentOperation;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Enums\OrganizationRole;

class ServerPolicy
{
    public function view(User $user, Server $server): bool
    {
        return $this->role($user, $server) !== null;
    }

    public function update(User $user, Server $server): bool
    {
        return $this->role($user, $server)?->canManageInfrastructure() ?? false;
    }

    public function delete(User $user, Server $server): bool
    {
        return $this->role($user, $server)?->canManageInfrastructure() ?? false;
    }

    public function issueEnrollment(User $user, Server $server): bool
    {
        return $this->role($user, $server)?->canManageInfrastructure() ?? false;
    }

    public function revokeCertificates(User $user, Server $server): bool
    {
        return $this->role($user, $server)?->canManageInfrastructure() ?? false;
    }

    public function executeCommand(User $user, Server $server): bool
    {
        return $this->role($user, $server)?->canOperate() ?? false;
    }

    public function executeOperation(User $user, Server $server, AgentOperation $operation): bool
    {
        $role = $this->role($user, $server);

        if ($role === null) {
            return false;
        }

        if ($operation->requiresOperationalRole()) {
            return $role->canOperate();
        }

        // Read-only operations are available to anyone who can either operate
        // a node or deploy to it.
        return $role->canOperate() || $role->canDeploy();
    }

    public function changeState(User $user, Server $server): bool
    {
        return $this->role($user, $server)?->canOperate() ?? false;
    }

    private function role(User $user, Server $server): ?OrganizationRole
    {
        $organization = $server->organization;

        if ($organization === null) {
            return null;
        }

        return $organization->memberships()
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->first()
            ?->role;
    }
}
