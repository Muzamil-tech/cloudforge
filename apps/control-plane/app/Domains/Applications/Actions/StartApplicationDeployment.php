<?php

namespace App\Domains\Applications\Actions;

use App\Domains\Applications\Enums\ApplicationStatus;
use App\Domains\Applications\Enums\DeploymentStatus;
use App\Domains\Applications\Models\Application;
use App\Domains\Applications\Models\Deployment;
use App\Domains\Applications\Support\ApplicationImage;
use App\Domains\Identity\Models\User;
use App\Domains\Operations\Enums\AgentOperation;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AgentCommandService;
use App\Domains\Operations\Services\AuditLogger;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StartApplicationDeployment
{
    public function __construct(
        private readonly AgentCommandService $commands,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Create a deployment record, issue the typed agent command and move the
     * application into its transitional deploying state.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function execute(
        Application $application,
        string $image,
        array $metadata,
        ?User $actor = null,
        ?string $deploymentId = null,
        ?string $containerName = null,
    ): Deployment {
        if (! ApplicationImage::isValid($image)) {
            throw ValidationException::withMessages([
                'image' => 'The deployment image is not a valid container image reference.',
            ]);
        }

        $deploymentId ??= (string) Str::uuid();
        $containerName ??= ApplicationImage::containerName($application->id, $deploymentId);

        $previousContainerName = array_key_exists('previous_container_name', $metadata)
            ? $metadata['previous_container_name']
            : $application->container_name;
        $previousStatus = array_key_exists('previous_status', $metadata)
            ? $metadata['previous_status']
            : $application->status->value;
        $previousDeploymentId = array_key_exists('previous_deployment_id', $metadata)
            ? $metadata['previous_deployment_id']
            : $application->latestDeployment?->id;

        $metadata = array_merge([
            'previous_container_name' => $previousContainerName,
            'previous_status' => $previousStatus,
            'previous_deployment_id' => $previousDeploymentId,
            'requested_by_user_id' => $actor?->id,
        ], $metadata);

        $deployment = Deployment::query()->create([
            'organization_id' => $application->organization_id,
            'project_id' => $application->project_id,
            'environment_id' => $application->environment_id,
            'application_id' => $application->id,
            'server_id' => $application->server_id,
            'image' => $image,
            'container_name' => $containerName,
            'status' => DeploymentStatus::Deploying,
            'started_at' => now(),
            'metadata' => $metadata,
        ]);

        $command = $this->commands->issue(
            $application->server,
            AgentOperation::ApplicationDeploy,
            [
                'application_id' => $application->id,
                'deployment_id' => $deployment->id,
                'container_name' => $containerName,
                'previous_container_name' => $previousContainerName,
                'image' => $image,
                'container_port' => $application->container_port,
                'health_path' => $application->health_path,
                'reason' => "Deploy {$application->name}",
            ],
            $actor,
            "application:{$application->id}:deploy:{$deployment->id}",
        );

        $deployment->forceFill(['command_id' => $command->command_id])->save();

        $application->forceFill([
            'status' => ApplicationStatus::Deploying,
            'last_command_id' => $command->command_id,
            'last_error' => null,
        ])->save();

        $this->auditLogger->record(
            AuditAction::DeploymentStarted,
            $application->organization,
            $deployment,
            [
                'application_id' => $application->id,
                'image' => $image,
                'command_id' => $command->command_id,
            ],
            $actor,
        );

        $deployment->events()->create([
            'level' => 'info',
            'message' => 'Deployment requested.',
            'context' => [
                'command_id' => $command->command_id,
                'image' => $image,
            ],
        ]);

        return $deployment->refresh();
    }
}
