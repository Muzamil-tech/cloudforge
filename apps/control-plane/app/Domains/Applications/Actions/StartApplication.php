<?php

namespace App\Domains\Applications\Actions;

use App\Domains\Applications\Enums\ApplicationStatus;
use App\Domains\Applications\Models\Application;
use App\Domains\Identity\Models\User;
use App\Domains\Operations\Enums\AgentOperation;
use App\Domains\Operations\Services\AgentCommandService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StartApplication
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

            if (! $locked->canStart()) {
                throw ValidationException::withMessages([
                    'application' => 'This application cannot be started from its current state.',
                ]);
            }

            $command = $this->commands->issue(
                $server,
                AgentOperation::ApplicationStart,
                [
                    'application_id' => $locked->id,
                    'container_name' => $locked->container_name,
                    'reason' => "Start {$locked->name}",
                ],
                $actor,
                (string) Str::uuid(),
            );

            $locked->forceFill([
                'status' => ApplicationStatus::Starting,
                'last_command_id' => $command->command_id,
                'last_error' => null,
            ])->save();

            return $locked;
        });
    }
}
