<?php

namespace App\Domains\Applications\Presenters;

use App\Domains\Applications\Models\Application;
use App\Domains\Applications\Models\Deployment;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Gate;

class ApplicationPresenter
{
    public function __construct(private readonly DeploymentPresenter $deployments) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(Application $application, User $user, bool $withDeployments = false): array
    {
        $gate = Gate::forUser($user);

        $payload = [
            'id' => $application->id,
            'name' => $application->name,
            'slug' => $application->slug,
            'image' => $application->image,
            'status' => $application->status->value,
            'status_label' => $application->status->label(),
            'status_tone' => $application->status->tone(),
            'container_name' => $application->container_name,
            'container_id' => $application->container_id,
            'container_port' => $application->container_port,
            'host' => $application->host,
            'host_port' => $application->host_port,
            'health_path' => $application->health_path,
            'project' => [
                'id' => $application->project->id,
                'name' => $application->project->name,
            ],
            'environment' => [
                'id' => $application->environment->id,
                'name' => $application->environment->name,
                'type' => $application->environment->type->value,
                'type_label' => $application->environment->type->label(),
            ],
            'server' => [
                'id' => $application->server->id,
                'name' => $application->server->name,
            ],
            'latest_deployment' => $application->latestDeployment
                ? $this->deployments->summary($application->latestDeployment)
                : null,
            'last_error' => $application->last_error,
            'can_deploy' => $application->canDeploy() && $gate->allows('deploy', $application),
            'can_start' => $application->canStart() && $gate->allows('start', $application),
            'can_stop' => $application->canStop() && $gate->allows('stop', $application),
            'can_refresh' => $application->canRefresh() && $gate->allows('refresh', $application),
            'can_delete' => $application->canDelete() && $gate->allows('delete', $application),
            'created_at' => $application->created_at?->toIso8601String(),
        ];

        if ($withDeployments) {
            $payload['deployments'] = $application->deployments
                ->map(fn (Deployment $deployment): array => $this->deployments->toArray($deployment, $application, $user))
                ->values()
                ->all();
        }

        return $payload;
    }
}
