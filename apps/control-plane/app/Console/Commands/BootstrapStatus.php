<?php

namespace App\Console\Commands;

use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Services\BootstrapCredentialWriter;
use Illuminate\Console\Command;

class BootstrapStatus extends Command
{
    protected $signature = 'cloudforge:bootstrap:status
                            {--status-path= : Override the 0600 status document path}
                            {--json : Emit the status document as JSON}';

    protected $description = 'Report the bootstrap server credential and live server state';

    public function handle(BootstrapCredentialWriter $writer): int
    {
        $statusPath = $this->option('status-path');

        if (is_string($statusPath) && trim($statusPath) !== '') {
            config(['cloudforge.bootstrap.status_path' => trim($statusPath)]);
        }

        $status = $writer->readStatus();
        $path = $writer->statusPath();

        if ($status === null) {
            $this->error("No readable bootstrap status document exists at {$path}.");

            return self::FAILURE;
        }

        $serverId = $status['server']['id'] ?? null;
        $server = is_string($serverId) ? Server::query()->find($serverId) : null;
        $certificate = $server?->activeCertificate();

        $live = [
            'status' => $server?->status->value,
            'claimed_by' => $server?->organization === null ? null : [
                'id' => $server->organization->id,
                'name' => $server->organization->name,
                'slug' => $server->organization->slug,
            ],
            'claimed_at' => $server?->claimed_at?->toIso8601String(),
            'agent_enrolled' => $certificate !== null,
            'certificate_expires_at' => $certificate?->expires_at?->toIso8601String(),
            'last_heartbeat_at' => $server?->last_heartbeat_at?->toIso8601String(),
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                [...$status, 'live' => $live],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return self::SUCCESS;
        }

        $this->info('CloudForge bootstrap status');
        $this->line("Status document: {$path}");
        $this->line("Provisioned at: {$status['provisioned_at']}");
        $this->line("Control plane: {$status['control_plane_url']}");
        $this->line("Server: {$status['server']['name']} ({$status['server']['id']})");
        $this->line("Hostname: {$status['server']['hostname']}");
        $this->line("Machine-id: {$status['server']['machine_id']}");

        if ($status['enrollment'] !== null) {
            $this->line("Enrollment token expires at: {$status['enrollment']['expires_at']}");
            $this->warn('An unused enrollment token remains in '.$status['credential_path'].'. Remove that file after installation.');
        } else {
            $this->line('Enrollment: already completed.');
        }

        if ($server === null) {
            $this->warn('The server record referenced by this document does not exist in this CloudForge database.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line("Live server status: {$live['status']}");

        if ($live['claimed_by'] === null) {
            $this->warn('This bootstrap server is unclaimed. Create the first organization to claim it.');
        } else {
            $this->line("Claimed by: {$live['claimed_by']['name']} ({$live['claimed_by']['slug']}) at {$live['claimed_at']}");
        }

        $this->line($live['agent_enrolled']
            ? "Agent certificate expires at: {$live['certificate_expires_at']}"
            : 'Agent certificate: not yet issued.');

        $this->line($live['last_heartbeat_at'] === null
            ? 'Last heartbeat: never'
            : "Last heartbeat: {$live['last_heartbeat_at']}");

        return self::SUCCESS;
    }
}
