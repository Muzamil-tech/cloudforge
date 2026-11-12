<?php

namespace App\Domains\Infrastructure\Services;

use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerCapability;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class RecordHeartbeat
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{agent_version?: string|null, docker_available?: bool, docker_version?: string|null, uptime_seconds?: int|null, load_average?: array<int, float>|null}  $heartbeat
     */
    public function execute(Server $server, array $heartbeat): Server
    {
        return DB::transaction(function () use ($server, $heartbeat): Server {
            /** @var Server $locked */
            $locked = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();

            $previous = $locked->status;
            $metadata = $locked->metadata ?? [];
            $dockerAvailable = (bool) ($heartbeat['docker_available'] ?? false);
            $restored = $metadata['status_before_unreachable'] ?? null;
            unset($metadata['status_before_unreachable']);

            $status = $locked->status;

            if ($status === ServerStatus::Unreachable && is_string($restored) && $restored !== '') {
                $restoredStatus = ServerStatus::tryFrom($restored);

                if ($restoredStatus?->isOperationalLock()) {
                    $status = $restoredStatus;
                } elseif (! $dockerAvailable) {
                    $status = ServerStatus::Error;
                } elseif ($restoredStatus === ServerStatus::Active) {
                    $status = ServerStatus::Active;
                } else {
                    $status = ServerStatus::Ready;
                }
            } elseif ($status->isOperationalLock()) {
                $status = $locked->status;
            } elseif (! $dockerAvailable) {
                $status = ServerStatus::Error;
            } elseif ($status === ServerStatus::Active || $status === ServerStatus::Ready) {
                $status = $locked->status;
            } else {
                $status = ServerStatus::Ready;
            }

            $metadata['last_heartbeat'] = [
                'uptime_seconds' => $heartbeat['uptime_seconds'] ?? null,
                'load_average' => $heartbeat['load_average'] ?? null,
                'docker_available' => $dockerAvailable,
            ];

            $locked->forceFill([
                'status' => $status,
                'agent_version' => $heartbeat['agent_version'] ?? $locked->agent_version,
                'docker_version' => $heartbeat['docker_version'] ?? $locked->docker_version,
                'last_heartbeat_at' => now(),
                'metadata' => $metadata,
            ])->save();

            ServerCapability::query()->updateOrCreate(
                ['server_id' => $locked->id, 'capability' => 'docker'],
                [
                    'version' => $heartbeat['docker_version'] ?? null,
                    'metadata' => ['available' => $dockerAvailable],
                ],
            );

            if ($previous !== $status) {
                $this->auditLogger->record(
                    AuditAction::ServerHeartbeat,
                    $locked->organization,
                    $locked,
                    [
                        'from' => $previous->value,
                        'to' => $status->value,
                        'docker_available' => $dockerAvailable,
                    ],
                );
            }

            return $locked;
        });
    }
}
