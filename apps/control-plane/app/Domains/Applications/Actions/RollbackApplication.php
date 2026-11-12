<?php

namespace App\Domains\Applications\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Applications\Models\Deployment;
use App\Domains\Applications\Support\ApplicationImage;
use App\Domains\Identity\Models\User;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RollbackApplication
{
    public function __construct(
        private readonly StartApplicationDeployment $deployments,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(Application $application, Deployment $target, ?User $actor = null): Deployment
    {
        return DB::transaction(function () use ($application, $target, $actor): Deployment {
            $locked = Application::query()->lockForUpdate()->findOrFail($application->id);
            $server = $locked->server;

            if ($server === null || ! $server->status->isReachable()) {
                throw ValidationException::withMessages([
                    'application' => 'The server hosting this application is not reachable.',
                ]);
            }

            if (! $locked->canDeploy()) {
                throw ValidationException::withMessages([
                    'application' => 'This application cannot be rolled back from its current state.',
                ]);
            }

            if ($target->application_id !== $locked->id) {
                throw ValidationException::withMessages([
                    'deployment' => 'The selected deployment does not belong to this application.',
                ]);
            }

            if (! ApplicationImage::isValid($target->image)) {
                throw ValidationException::withMessages([
                    'deployment' => 'The selected deployment does not have a valid image.',
                ]);
            }

            $deployment = $this->deployments->execute($locked, $target->image, [
                'rollback_of_deployment_id' => $target->id,
            ], $actor);

            $deployment->events()->create([
                'level' => 'info',
                'message' => "Rollback to deployment {$target->id} requested.",
                'context' => ['rollback_of_deployment_id' => $target->id, 'image' => $target->image],
            ]);

            $this->auditLogger->record(
                AuditAction::DeploymentRolledBack,
                $locked->organization,
                $deployment,
                [
                    'application_id' => $locked->id,
                    'source_deployment_id' => $target->id,
                    'image' => $target->image,
                ],
                $actor,
            );

            return $deployment;
        });
    }
}
