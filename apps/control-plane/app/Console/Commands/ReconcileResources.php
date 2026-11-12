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

        Resource::query()
            ->whereIn('status', array_map(
                fn (ResourceStatus $status): string => $status->value,
                ResourceStatus::transitional(),
            ))
            ->whereNotNull('last_command_id')
            ->orderBy('id')
            ->chunkById(100, function ($resources) use ($results, $auditLogger, &$changed): void {
                foreach ($resources as $resource) {
                    $command = AgentCommand::query()
                        ->where('command_id', $resource->last_command_id)
                        ->first();

                    if ($command === null) {
                        $this->failResource($resource, 'The provisioning command could not be found.', $auditLogger);
                        $changed++;

                        continue;
                    }

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
                        $this->failResource(
                            $resource,
                            $command->error_message ?: 'The agent did not complete the resource operation before it expired.',
                            $auditLogger,
                        );
                        $changed++;
                    }
                }
            });

        $this->info("{$changed} resource operation(s) reconciled.");

        return self::SUCCESS;
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
