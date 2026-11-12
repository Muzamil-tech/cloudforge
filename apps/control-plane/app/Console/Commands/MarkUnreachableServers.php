<?php

namespace App\Console\Commands;

use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use Illuminate\Console\Command;

class MarkUnreachableServers extends Command
{
    protected $signature = 'servers:mark-unreachable
                            {--timeout= : Heartbeat timeout in seconds (defaults to configuration)}
                            {--dry-run : Report servers without changing their state}';

    protected $description = 'Mark servers whose last heartbeat exceeds the timeout as unreachable';

    public function handle(AuditLogger $auditLogger): int
    {
        $timeout = (int) ($this->option('timeout') ?: config('cloudforge.agent.heartbeat_timeout_seconds', 90));
        $cutoff = now()->subSeconds($timeout);
        $dryRun = (bool) $this->option('dry-run');
        $marked = 0;

        Server::query()
            ->whereIn('status', [
                ServerStatus::Ready->value,
                ServerStatus::Active->value,
                ServerStatus::Draining->value,
                ServerStatus::Maintenance->value,
            ])
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($servers) use ($auditLogger, $dryRun, &$marked): void {
                foreach ($servers as $server) {
                    if ($dryRun) {
                        $this->line("Would mark {$server->name} ({$server->status->value}) unreachable.");
                        $marked++;

                        continue;
                    }

                    $previous = $server->status;
                    $metadata = $server->metadata ?? [];
                    $metadata['status_before_unreachable'] = $previous->value;

                    $server->forceFill([
                        'status' => ServerStatus::Unreachable,
                        'metadata' => $metadata,
                    ])->save();

                    $auditLogger->record(
                        AuditAction::ServerUnreachable,
                        $server->organization,
                        $server,
                        [
                            'from' => $previous->value,
                            'timeout_seconds' => (int) $this->option('timeout') ?: null,
                            'last_heartbeat_at' => $server->last_heartbeat_at?->toIso8601String(),
                        ],
                    );

                    $marked++;
                }
            });

        $this->info($dryRun
            ? "{$marked} server(s) would be marked unreachable."
            : "{$marked} server(s) marked unreachable.");

        return self::SUCCESS;
    }
}
