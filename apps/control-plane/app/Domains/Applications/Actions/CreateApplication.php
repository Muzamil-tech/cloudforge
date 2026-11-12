<?php

namespace App\Domains\Applications\Actions;

use App\Domains\Applications\Enums\ApplicationStatus;
use App\Domains\Applications\Models\Application;
use App\Domains\Applications\Support\ApplicationImage;
use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Projects\Models\Environment;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateApplication
{
    public function __construct(
        private readonly StartApplicationDeployment $deployments,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(
        Project $project,
        Environment $environment,
        Server $server,
        string $name,
        string $image,
        int $containerPort,
        ?string $healthPath,
        ?User $actor = null,
    ): Application {
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

        if (! ApplicationImage::isValid($image)) {
            throw ValidationException::withMessages([
                'image' => 'The image must be a valid container image reference.',
            ]);
        }

        $duplicate = Application::withTrashed()
            ->where('project_id', $project->id)
            ->where('environment_id', $environment->id)
            ->where(function ($query) use ($name): void {
                $query->where('name', $name)->orWhere('slug', Str::slug($name) ?: 'application');
            })
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => 'An application with this name already exists in the environment.',
            ]);
        }

        return DB::transaction(function () use (
            $project,
            $environment,
            $server,
            $name,
            $image,
            $containerPort,
            $healthPath,
            $actor,
        ): Application {
            $id = (string) Str::uuid();
            $deploymentId = (string) Str::uuid();
            $containerName = ApplicationImage::containerName($id, $deploymentId);

            $application = new Application;
            $application->forceFill([
                'id' => $id,
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'environment_id' => $environment->id,
                'server_id' => $server->id,
                'name' => $name,
                'slug' => $this->uniqueSlug($project, $environment, $name),
                'image' => $image,
                'container_name' => $containerName,
                'container_port' => $containerPort,
                'host' => '127.0.0.1',
                'health_path' => $healthPath,
                'status' => ApplicationStatus::Deploying,
                'metadata' => [],
            ])->save();

            $this->deployments->execute(
                $application,
                $image,
                [
                    'previous_container_name' => null,
                    'previous_status' => null,
                    'previous_deployment_id' => null,
                ],
                $actor,
                $deploymentId,
                $containerName,
            );

            $this->auditLogger->record(
                AuditAction::ApplicationCreated,
                $project->organization,
                $application,
                [
                    'environment_id' => $environment->id,
                    'server_id' => $server->id,
                    'image' => $image,
                ],
                $actor,
            );

            return $application->refresh();
        });
    }

    private function uniqueSlug(Project $project, Environment $environment, string $name): string
    {
        $base = Str::slug($name) ?: 'application';
        $slug = $base;
        $suffix = 2;

        while (Application::withTrashed()
            ->where('project_id', $project->id)
            ->where('environment_id', $environment->id)
            ->where('slug', $slug)
            ->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
