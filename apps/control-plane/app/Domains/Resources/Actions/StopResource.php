<?php

namespace App\Domains\Resources\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Operations\Enums\AgentOperation;
use App\Domains\Operations\Services\AgentCommandService;
use App\Domains\Resources\Enums\ResourceStatus;
use App\Domains\Resources\Models\Resource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StopResource
{
    public function __construct(private readonly AgentCommandService $commands) {}

    public function execute(Resource $resource, ?User $actor = null): Resource
    {
        return DB::transaction(function () use ($resource, $actor): Resource {
            $locked = Resource::query()->lockForUpdate()->findOrFail($resource->id);
            $server = $locked->server;

            if ($server === null || ! $server->status->isReachable()) {
                throw ValidationException::withMessages([
                    'resource' => 'The server hosting this resource is not reachable.',
                ]);
            }

            if (! $locked->status->canStop()) {
                throw ValidationException::withMessages([
                    'resource' => 'This resource cannot be stopped from its current state.',
                ]);
            }

            $command = $this->commands->issue(
                $server,
                AgentOperation::ResourceStop,
                [
                    'resource_id' => $locked->id,
                    'container_name' => $locked->container_name,
                    'reason' => "Stop {$locked->name}",
                ],
                $actor,
                (string) Str::uuid(),
            );

            $locked->forceFill([
                'status' => ResourceStatus::Stopping,
                'last_command_id' => $command->command_id,
                'last_error' => null,
            ])->save();

            return $locked;
        });
    }
}
