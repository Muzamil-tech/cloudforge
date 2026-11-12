<?php

namespace App\Console\Commands;

use App\Domains\Applications\Actions\HandleApplicationCommandResult;
use App\Domains\Applications\Enums\ApplicationStatus;
use App\Domains\Applications\Enums\DeploymentStatus;
use App\Domains\Applications\Models\Application;
use App\Domains\Operations\Enums\AgentCommandStatus;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Models\AgentCommand;
use App\Domains\Operations\Services\AuditLogger;
use Illuminate\Console\Command;

class ReconcileApplications extends Command
{
    protected $signature = 'applications:reconcile';

    protected $description = 'Fail or finish applications whose agent command has expired or completed';

    public function handle(
        HandleApplicationCommandResult $results,
        AuditLogger $auditLogger,
    ): int {
        $changed = 0;
        $waiting = 0;

        Application::query()
            ->whereIn('status', array_map(
                fn (ApplicationStatus $status): string => $status->value,
                ApplicationStatus::transitional(),
            ))
            ->chunkById(100, function ($applications) use ($results, $auditLogger, &$changed, &$waiting): void {
                foreach ($applications as $application) {
                    if ($application->last_command_id === null) {
                        $this->failApplication($application, 'The application has no deployment command to complete.', $auditLogger);
                        $changed++;

                        continue;
                    }

                    $command = AgentCommand::query()
                        ->where('command_id', $application->last_command_id)
                        ->first();

                    if ($command === null) {
                        $this->failApplication($application, 'The application command could not be found.', $auditLogger);
                        $changed++;

                        continue;
                    }

                    if ($this->output->isVerbose()) {
                        $this->line(sprintf(
                            '%s [%s] command=%s operation=%s status=%s attempts=%d expires=%s',
                            $application->name,
                            $application->status->value,
                            $command->command_id,
                            $command->operation->value,
                            $command->status->value,
                            $command->attempts,
                            $command->expires_at?->toIso8601String() ?? 'unknown',
                        ));
                    }

                    if ($command->status === AgentCommandStatus::Completed) {
                        $results->handle($command, true, $command->result ?? [], null);

                        $fresh = $application->fresh();

                        if ($fresh !== null && $fresh->status->isTransitional()) {
                            $this->failApplication($application, 'The agent completed without a usable application result.', $auditLogger);
                        }

                        $changed++;

                        continue;
                    }

                    if ($command->status->isTerminal() || $command->expires_at->isPast()) {
                        $message = $command->error_message
                            ?: 'The agent did not complete the application operation before it expired.';

                        $this->expireCommand($command, $message);
                        $results->handle($command, false, [], $message);
                        $changed++;

                        continue;
                    }

                    $waiting++;
                }
            });

        $this->info("{$changed} application operation(s) reconciled.");

        if ($waiting > 0) {
            $this->line("{$waiting} application operation(s) are still waiting for an active agent command.");
        }

        return self::SUCCESS;
    }

    private function expireCommand(AgentCommand $command, string $message): void
    {
        if ($command->status->isTerminal()) {
            return;
        }

        $command->forceFill([
            'status' => AgentCommandStatus::Expired,
            'error_message' => mb_substr($message, 0, 2000),
            'completed_at' => now(),
        ])->save();
    }

    private function failApplication(Application $application, string $message, AuditLogger $auditLogger): void
    {
        $deployment = $application->deployments()
            ->where('status', DeploymentStatus::Deploying->value)
            ->latest()
            ->first();

        if ($deployment !== null) {
            $deployment->forceFill([
                'status' => DeploymentStatus::Failed,
                'error_message' => mb_substr($message, 0, 2000),
                'finished_at' => now(),
            ])->save();

            $deployment->events()->create([
                'level' => 'error',
                'message' => mb_substr($message, 0, 2000),
            ]);

            $previousContainerName = $deployment->metadata['previous_container_name'] ?? null;

            if (is_string($previousContainerName) && $previousContainerName !== '') {
                $previousStatus = $deployment->metadata['previous_status'] ?? null;
                $status = $previousStatus === ApplicationStatus::Stopped->value
                    ? ApplicationStatus::Stopped
                    : ApplicationStatus::Active;
            } else {
                $status = ApplicationStatus::Failed;
            }
        } else {
            $status = ApplicationStatus::Failed;
        }

        $application->forceFill([
            'status' => $status,
            'last_error' => mb_substr($message, 0, 2000),
        ])->save();

        $auditLogger->record(AuditAction::ApplicationFailed, $application->organization, $application, [
            'reason' => 'command_reconciliation',
        ]);
    }
}
