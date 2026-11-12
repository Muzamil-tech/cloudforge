<?php

namespace App\Domains\Resources\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Operations\Enums\AgentOperation;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AgentCommandService;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Resources\Enums\ResourceStatus;
use App\Domains\Resources\Models\Resource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecoverResource
{
    public function __construct(
        private readonly AgentCommandService $commands,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Re-issue an idempotent create for a resource whose credentials were lost.
     * The agent inspects an existing managed container, recovers its password
     * and returns it again; if the container is gone, it creates a new one.
     */
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

            if (! $locked->canRecoverCredentials()) {
                throw ValidationException::withMessages([
                    'resource' => 'Credentials are already available or the resource cannot be recovered from its current state.',
                ]);
            }

            $command = $this->commands->issue(
                $server,
                AgentOperation::ResourceCreate,
                [
                    'resource_id' => $locked->id,
                    'resource_type' => $locked->type->value,
                    'container_name' => $locked->container_name,
                    'volume_name' => $locked->volume_name,
                    'reason' => "Recover {$locked->name}",
                ],
                $actor,
                (string) Str::uuid(),
            );

            $locked->forceFill([
                'status' => ResourceStatus::Provisioning,
                'last_command_id' => $command->command_id,
                'last_error' => null,
            ])->save();

            $this->auditLogger->record(
                AuditAction::ResourceRecoveryRequested,
                $locked->organization,
                $locked,
                [
                    'type' => $locked->type->value,
                    'command_id' => $command->command_id,
                ],
                $actor,
            );

            return $locked;
        });
    }
}
