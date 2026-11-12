<?php

namespace App\Console\Commands;

use App\Domains\Infrastructure\Actions\PrepareBootstrapServer;
use App\Domains\Infrastructure\Services\BootstrapCredentialWriter;
use App\Domains\Infrastructure\Services\HostInspector;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class BootstrapServer extends Command
{
    protected $signature = 'cloudforge:bootstrap
                            {--name= : Display name for the bootstrap server}
                            {--url= : Public control plane URL written to the credential (defaults to cloudforge.bootstrap.control_plane_url)}
                            {--credential-path= : Override the 0600 credential file path}
                            {--status-path= : Override the 0600 status document path}
                            {--json : Emit a machine-readable summary (never includes the token)}';

    protected $description = 'Register the local host as the unclaimed CloudForge bootstrap server and write its enrollment credential';

    public function handle(
        HostInspector $inspector,
        PrepareBootstrapServer $prepareBootstrapServer,
        BootstrapCredentialWriter $writer,
    ): int {
        $url = $this->option('url');

        if (is_string($url) && trim($url) !== '') {
            $url = rtrim(trim($url), '/');

            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                $this->error("The control plane URL '{$url}' is not a valid URL.");

                return self::FAILURE;
            }

            config(['cloudforge.bootstrap.control_plane_url' => $url]);
        }

        $credentialPath = $this->option('credential-path');

        if (is_string($credentialPath) && trim($credentialPath) !== '') {
            config(['cloudforge.bootstrap.credential_path' => trim($credentialPath)]);
        }

        $statusPath = $this->option('status-path');

        if (is_string($statusPath) && trim($statusPath) !== '') {
            config(['cloudforge.bootstrap.status_path' => trim($statusPath)]);
        }

        try {
            $facts = $inspector->inspect();

            $result = $prepareBootstrapServer->execute(
                $facts,
                $this->option('name') ?: null,
            );

            $files = $writer->write(
                $result['server'],
                $result['enrollment'],
                $result['token'],
            );
        } catch (ValidationException $exception) {
            return $this->reportValidationFailure($exception);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('The bootstrap server could not be provisioned: '.$exception->getMessage());

            return self::FAILURE;
        }

        $server = $result['server'];

        $summary = [
            'server_id' => $server->id,
            'server_name' => $server->name,
            'hostname' => $server->hostname,
            'machine_id' => $server->machine_id,
            'status' => $server->status->value,
            'created' => $result['created'],
            'already_enrolled' => $result['already_enrolled'],
            'enrollment_id' => $result['enrollment']?->id,
            'enrollment_expires_at' => $result['enrollment']?->expires_at->toIso8601String(),
            'credential_path' => $files['credential_path'],
            'status_path' => $files['status_path'],
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return self::SUCCESS;
        }

        $this->info("Bootstrap server {$server->name} ({$server->id}) is registered.");
        $this->line("Host: {$server->hostname} (machine-id {$server->machine_id})");

        if ($result['already_enrolled']) {
            $this->line('This host already holds an active agent certificate; no new enrollment token was issued.');
        } else {
            $this->line("Enrollment token expires at {$result['enrollment']?->expires_at->toIso8601String()} and is consumed on first use.");
        }

        $this->line("Enrollment credential: {$files['credential_path']} (mode 0600, remove after installation)");
        $this->line("Status document: {$files['status_path']} (mode 0600, contains no token)");
        $this->line('The token is never printed to the console or written to the agent environment file.');

        return self::SUCCESS;
    }

    private function reportValidationFailure(ValidationException $exception): int
    {
        foreach ($exception->errors() as $messages) {
            foreach ($messages as $message) {
                $this->error($message);
            }
        }

        return self::FAILURE;
    }
}
