<?php

namespace App\Domains\Applications\Presenters;

use App\Domains\Applications\Models\Application;
use App\Domains\Applications\Models\Deployment;
use App\Domains\Applications\Models\DeploymentEvent;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Gate;

class DeploymentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Deployment $deployment): array
    {
        return [
            'id' => $deployment->id,
            'image' => $deployment->image,
            'status' => $deployment->status->value,
            'status_label' => $deployment->status->label(),
            'status_tone' => $deployment->status->tone(),
            'container_name' => $deployment->container_name,
            'host_port' => $deployment->host_port,
            'error_message' => $deployment->error_message,
            'started_at' => $deployment->started_at?->toIso8601String(),
            'finished_at' => $deployment->finished_at?->toIso8601String(),
            'created_at' => $deployment->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Deployment $deployment, Application $application, User $user): array
    {
        return array_merge($this->summary($deployment), [
            'container_id' => $deployment->container_id,
            'logs' => $deployment->logs,
            'metadata' => $deployment->metadata,
            'can_rollback' => $application->canDeploy()
                && $deployment->isNot($application->latestDeployment)
                && $deployment->image !== ''
                && Gate::forUser($user)->allows('rollback', $application),
            'events' => $deployment->events
                ->map(fn (DeploymentEvent $event): array => [
                    'id' => $event->id,
                    'level' => $event->level,
                    'message' => $event->message,
                    'context' => $event->context,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ]);
    }
}
