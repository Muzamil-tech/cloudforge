<?php

namespace App\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Operations\Models\AgentCommand;
use Illuminate\Support\Facades\Gate;

class AgentCommandPolicy
{
    public function view(User $user, AgentCommand $command): bool
    {
        return $command->server !== null && Gate::forUser($user)->allows('view', $command->server);
    }

    public function cancel(User $user, AgentCommand $command): bool
    {
        return $command->server !== null && Gate::forUser($user)->allows('executeCommand', $command->server);
    }
}
