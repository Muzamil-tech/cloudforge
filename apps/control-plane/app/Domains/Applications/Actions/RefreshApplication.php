<?php

namespace App\Domains\Applications\Actions;

use App\Domains\Applications\Enums\ApplicationStatus;
use App\Domains\Applications\Models\Application;
use App\Domains\Identity\Models\User;
use App\Domains\Operations\Enums\AgentOperation;
use App\Domains\Operations\Services\AgentCommandService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefreshApplication
{
    public function __construct(private readonly AgentCommandService $commands) {}

    public function execute(Application $application, ?User $actor = null): Application
    {
        return DB::transaction(function () use ($application, $actor): Application {
            $locked = Application::query()->lockForUpdate()->findOrFail($application->id);
            $server = $locked->server;

            if ($server === null || ! $server->status->isReachable()) {
                throw ValidationException::withMessages([
                    'application' => 'The server hosting this application is not reachable.',
                ]);
            }

            if (! $locked->canRefresh()) {
                throw ValidationException::withMessages([
                    'application' => 'Wait for the current application operation to finish.',
                ]);
            }

            $command = $this->commands->issue(
                $server,
                AgentOperation::ApplicationInspect,
                [
                    'application_id' => $locked->id,
                    'container_name' => $locked->container_name,
                    'container_port' => $locked->container_port,
                    'reason' => "Inspect {$locked->name}",
                ],
                $actor,
            );

            $locked->forceFill([
                'status' => ApplicationStatus::Refreshing,
                'last_command_id' => $command->command_id,
                'last_error' => null,
            ])->save();

            return $locked;
        });
    }
}
