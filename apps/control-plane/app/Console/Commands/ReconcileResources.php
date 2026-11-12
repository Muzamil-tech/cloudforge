<?php

namespace App\Console\Commands;

use App\Domains\Operations\Enums\AgentCommandStatus;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Models\AgentCommand;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Resources\Actions\HandleResourceCommandResult;
use App\Domains\Resources\Enums\ResourceStatus;
use App\Domains\Resources\Models\Resource;
use Illuminate\Console\Command;

class ReconcileResources extends Command
{
    protected $signature = 'resources:reconcile';

    protected $description = 'Fail or finish resources whose agent command has expired or completed';

    public function handle(
        HandleResourceCommandResult $results,
        AuditLogger $auditLogger,
    ): int {
        $changed = 0;
        $waiting = 0;

        Resource::query()
            ->whereIn('status', array_map(
                fn (ResourceStatus $status): string => $status->value,
                ResourceStatus::transitional(),
            ))
            ->orderBy('id')
            ->chunkById(100, function ($resources) use ($results, $auditLogger, &$changed, &$waiting): void {
                foreach ($resources as $resource) {
                    if ($resource->last_command_id === null) {
                        $this->failResource($resource, 'The resource has no provisioning command to complete.', $auditLogger);
                        $changed++;

                        continue;
                    }

                    $command = AgentCommand::query()
                        ->where('command_id', $resource->last_command_id)
                        ->first();

                    if ($command === null) {
                        $this->failResource($resource, 'The provisioning command could not be found.', $auditLogger);
                        $changed++;

                        continue;
                    }

                    $this->describe($resource, $command);

                    if ($command->status === AgentCommandStatus::Completed) {
                        $results->handle($command, true, $command->result ?? [], null);

                        $fresh = $resource->fresh();

                        if ($fresh !== null && $fresh->status->isTransitional()) {
                            $this->failResource($resource, 'The agent completed without a usable resource result.', $auditLogger);
                        }

                        $changed++;

                        continue;
                    }

                    if ($command->status->isTerminal() || $command->expires_at->isPast()) {
                        $message = $command->error_message
                            ?: 'The agent did not complete the resource operation before it expired.';

                        $this->expireCommand($command, $message);
                        $this->failResource($resource, $message, $auditLogger);
                        $changed++;

                        continue;
                    }

                    $waiting++;
                }
            });

        $this->info("{$changed} resource operation(s) reconciled.");

        if ($waiting > 0) {
            $this->line("{$waiting} resource operation(s) are still waiting for an active agent command.");
        }

        return self::SUCCESS;
    }

    private function describe(Resource $resource, AgentCommand $command): void
    {
        if (! $this->output->isVerbose()) {
            return;
        }

        $this->line(sprintf(
            '%s [%s] command=%s operation=%s status=%s attempts=%d expires=%s',
            $resource->name,
            $resource->status->value,
            $command->command_id,
            $command->operation->value,
            $command->status->value,
            $command->attempts,
            $command->expires_at?->toIso8601String() ?? 'unknown',
        ));
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

    private function failResource(Resource $resource, string $message, AuditLogger $auditLogger): void
    {
        $resource->forceFill([
            'status' => ResourceStatus::Failed,
            'last_error' => mb_substr($message, 0, 2000),
        ])->save();

        $auditLogger->record(AuditAction::ResourceFailed, $resource->organization, $resource, [
            'type' => $resource->type->value,
            'reason' => 'command_reconciliation',
        ]);
    }
}
