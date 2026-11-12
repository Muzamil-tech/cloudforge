<?php

namespace App\Domains\Resources\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Operations\Enums\AgentOperation;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AgentCommandService;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Projects\Models\Environment;
use App\Domains\Projects\Models\Project;
use App\Domains\Resources\Enums\ResourceStatus;
use App\Domains\Resources\Enums\ResourceType;
use App\Domains\Resources\Models\Resource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateResource
{
    public function __construct(
        private readonly AgentCommandService $commands,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(
        Project $project,
        Environment $environment,
        Server $server,
        string $name,
        ResourceType $type,
        ?User $actor = null,
    ): Resource {
        if ($environment->project_id !== $project->id || $environment->organization_id !== $project->organization_id) {
            throw ValidationException::withMessages([
                'environment_id' => 'The selected environment does not belong to this project.',
            ]);
        }

        if ($server->organization_id !== $project->organization_id) {
            throw ValidationException::withMessages([
                'server_id' => 'The selected server does not belong to this organization.',
            ]);
        }

        if (! $server->status->isReachable() || trim((string) $server->docker_version) === '') {
            throw ValidationException::withMessages([
                'server_id' => 'The selected server is not reachable with Docker available.',
            ]);
        }

        if (Resource::query()
            ->where('project_id', $project->id)
            ->where('environment_id', $environment->id)
            ->where('name', $name)
            ->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A resource with this name already exists in the environment.',
            ]);
        }

        return DB::transaction(function () use ($project, $environment, $server, $name, $type, $actor): Resource {
            $id = (string) Str::uuid();

            $resource = Resource::query()->create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'environment_id' => $environment->id,
                'server_id' => $server->id,
                'name' => $name,
                'type' => $type,
                'status' => ResourceStatus::Provisioning,
                'image' => $type->image(),
                'container_name' => 'cloudforge-'.$id,
                'volume_name' => 'cloudforge-'.$id.'-data',
                'host' => '127.0.0.1',
                'metadata' => [],
            ]);

            $command = $this->commands->issue(
                $server,
                AgentOperation::ResourceCreate,
                [
                    'resource_id' => $resource->id,
                    'resource_type' => $type->value,
                    'container_name' => $resource->container_name,
                    'volume_name' => $resource->volume_name,
                    'reason' => "Provision {$resource->name}",
                ],
                $actor,
                "resource:{$resource->id}:create",
            );

            $resource->forceFill(['last_command_id' => $command->command_id])->save();

            $this->auditLogger->record(
                AuditAction::ResourceCreated,
                $project->organization,
                $resource,
                [
                    'type' => $type->value,
                    'environment_id' => $environment->id,
                    'server_id' => $server->id,
                ],
                $actor,
            );

            return $resource->refresh();
        });
    }
}
