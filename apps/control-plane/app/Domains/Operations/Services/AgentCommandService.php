<?php

namespace App\Domains\Operations\Services;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Operations\Enums\AgentCommandStatus;
use App\Domains\Operations\Enums\AgentOperation;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Models\AgentCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AgentCommandService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function issue(
        Server $server,
        AgentOperation $operation,
        array $payload = [],
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): AgentCommand {
        Validator::make($payload, $operation->payloadRules())->validate();

        $idempotencyKey ??= (string) Str::uuid();

        $existing = AgentCommand::query()
            ->where('server_id', $server->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if ($existing->operation !== $operation) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'This idempotency key is already used by a different operation.',
                ]);
            }

            return $existing;
        }

        $command = AgentCommand::query()->create([
            'server_id' => $server->id,
            'command_id' => (string) Str::uuid(),
            'operation' => $operation,
            'payload' => $payload,
            'status' => AgentCommandStatus::Pending,
            'idempotency_key' => $idempotencyKey,
            'issued_at' => now(),
            'expires_at' => now()->addSeconds($operation->ttlSeconds()),
            'created_by_user_id' => $actor?->id,
        ]);

        $this->auditLogger->record(
            AuditAction::AgentCommandIssued,
            $server->organization,
            $command,
            ['operation' => $operation->value, 'command_id' => $command->command_id],
            $actor,
        );

        return $command;
    }

    public function nextFor(Server $server): ?AgentCommand
    {
        return DB::transaction(function () use ($server): ?AgentCommand {
            AgentCommand::query()
                ->where('server_id', $server->id)
                ->whereIn('status', [AgentCommandStatus::Pending, AgentCommandStatus::Acknowledged])
                ->where('expires_at', '<=', now())
                ->update(['status' => AgentCommandStatus::Expired, 'completed_at' => now()]);

            /** @var AgentCommand|null $command */
            $command = AgentCommand::query()
                ->where('server_id', $server->id)
                ->where('expires_at', '>', now())
                ->where(function ($query): void {
                    $query->where('status', AgentCommandStatus::Pending)
                        ->orWhere(function ($query): void {
                            $query->where('status', AgentCommandStatus::Acknowledged)
                                ->where('acknowledged_at', '<', now()->subMinute());
                        });
                })
                ->orderBy('issued_at')
                ->lockForUpdate()
                ->first();

            if ($command === null) {
                return null;
            }

            $command->forceFill(['attempts' => $command->attempts + 1])->save();

            return $command;
        });
    }

    public function acknowledge(AgentCommand $command, Server $server): AgentCommand
    {
        $this->ensureOwnership($command, $server);

        if ($command->isTerminal()) {
            return $command;
        }

        if ($command->status === AgentCommandStatus::Pending) {
            $command->status = AgentCommandStatus::Acknowledged;
        }

        $command->forceFill([
            'acknowledged_at' => now(),
            'started_at' => $command->started_at ?? now(),
        ])->save();

        $this->auditLogger->record(
            AuditAction::AgentCommandAcknowledged,
            $server->organization,
            $command,
            ['operation' => $command->operation->value, 'command_id' => $command->command_id],
        );

        return $command;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function complete(
        AgentCommand $command,
        Server $server,
        bool $succeeded,
        array $result = [],
        ?string $errorMessage = null,
    ): AgentCommand {
        $this->ensureOwnership($command, $server);

        if ($command->isTerminal()) {
            return $command;
        }

        $command->forceFill([
            'status' => $succeeded ? AgentCommandStatus::Completed : AgentCommandStatus::Failed,
            'result' => $result === [] ? null : $result,
            'error_message' => $succeeded ? null : ($errorMessage ?? 'The agent reported a failure.'),
            'completed_at' => now(),
            'started_at' => $command->started_at ?? now(),
        ])->save();

        $this->auditLogger->record(
            $succeeded ? AuditAction::AgentCommandCompleted : AuditAction::AgentCommandFailed,
            $server->organization,
            $command,
            [
                'operation' => $command->operation->value,
                'command_id' => $command->command_id,
                'error' => $command->error_message,
            ],
        );

        return $command;
    }

    public function cancel(AgentCommand $command, Server $server, ?User $actor = null): AgentCommand
    {
        $this->ensureOwnership($command, $server);

        if ($command->isTerminal()) {
            return $command;
        }

        $command->forceFill([
            'status' => AgentCommandStatus::Cancelled,
            'completed_at' => now(),
        ])->save();

        $this->auditLogger->record(
            AuditAction::AgentCommandCancelled,
            $server->organization,
            $command,
            ['operation' => $command->operation->value],
            $actor,
        );

        return $command;
    }

    private function ensureOwnership(AgentCommand $command, Server $server): void
    {
        abort_unless($command->server_id === $server->id, 404);
    }
}
