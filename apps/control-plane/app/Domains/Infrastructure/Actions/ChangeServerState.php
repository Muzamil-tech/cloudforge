<?php

namespace App\Domains\Infrastructure\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use Illuminate\Validation\ValidationException;

class ChangeServerState
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Apply an operator-requested node lifecycle state.
     */
    public function execute(Server $server, ServerStatus $target, User $actor): Server
    {
        $previous = $server->status;

        if ($previous === $target) {
            return $server;
        }

        if (! $previous->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => "Cannot move a server from {$previous->label()} to {$target->label()}.",
            ]);
        }

        $metadata = $server->metadata ?? [];
        unset($metadata['status_before_unreachable']);

        $server->forceFill([
            'status' => $target,
            'metadata' => $metadata,
        ])->save();

        $this->auditLogger->record(
            match ($target) {
                ServerStatus::Draining => AuditAction::ServerDrained,
                ServerStatus::Maintenance => AuditAction::ServerMaintenance,
                default => AuditAction::ServerResumed,
            },
            $server->organization,
            $server,
            ['from' => $previous->value, 'to' => $target->value],
            $actor,
        );

        return $server;
    }
}
