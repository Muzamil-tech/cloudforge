<?php

namespace App\Domains\Applications\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Applications\Models\Deployment;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeployApplication
{
    public function __construct(private readonly StartApplicationDeployment $deployments) {}

    public function execute(Application $application, ?User $actor = null): Deployment
    {
        return DB::transaction(function () use ($application, $actor): Deployment {
            $locked = Application::query()->lockForUpdate()->findOrFail($application->id);
            $server = $locked->server;

            if ($server === null || ! $server->status->isReachable()) {
                throw ValidationException::withMessages([
                    'application' => 'The server hosting this application is not reachable.',
                ]);
            }

            if (! $locked->canDeploy()) {
                throw ValidationException::withMessages([
                    'application' => 'This application cannot be deployed from its current state.',
                ]);
            }

            return $this->deployments->execute($locked, $locked->image, [], $actor);
        });
    }
}
