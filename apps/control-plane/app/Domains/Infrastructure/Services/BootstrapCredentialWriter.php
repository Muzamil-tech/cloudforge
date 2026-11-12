<?php

namespace App\Domains\Infrastructure\Services;

use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerEnrollment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Writes the short-lived bootstrap enrollment credential for the local
 * installation wrapper.
 *
 * Two files are produced:
 *
 *  - a shell-sourceable environment file (0600) that contains the token, and
 *  - a JSON status file (0600) that deliberately never contains the token.
 *
 * Both files are written to a temporary file in the destination directory and
 * renamed into place so a crash can never leave a partially written
 * credential behind.
 */
class BootstrapCredentialWriter
{
    public function __construct(
        private readonly ?string $credentialPath = null,
        private readonly ?string $statusPath = null,
    ) {}

    /**
     * @return array{credential_path: string, status_path: string, already_enrolled: bool}
     */
    public function write(Server $server, ?ServerEnrollment $enrollment, ?string $token): array
    {
        if (($enrollment === null) !== ($token === null)) {
            throw new RuntimeException('A bootstrap credential must include both an enrollment and its token.');
        }

        if ($token !== null && preg_match('/^[A-Za-z0-9_-]{16,256}$/', $token) !== 1) {
            throw new RuntimeException('The bootstrap enrollment token is malformed.');
        }

        $credentialPath = $this->credentialPath();
        $statusPath = $this->statusPath();

        if ($token !== null) {
            $this->writeFile($credentialPath, $this->credentialContents($server, $token));
        } elseif (File::exists($credentialPath)) {
            File::delete($credentialPath);
        }

        $this->writeFile($statusPath, $this->statusContents($server, $enrollment));

        return [
            'credential_path' => $credentialPath,
            'status_path' => $statusPath,
            'already_enrolled' => $enrollment === null,
        ];
    }

    public function credentialPath(): string
    {
        return $this->credentialPath ?? (string) config(
            'cloudforge.bootstrap.credential_path',
            '/etc/cloudforge/bootstrap.env',
        );
    }

    public function statusPath(): string
    {
        return $this->statusPath ?? (string) config(
            'cloudforge.bootstrap.status_path',
            '/etc/cloudforge/bootstrap.json',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readStatus(): ?array
    {
        $path = $this->statusPath();

        if (! File::exists($path)) {
            return null;
        }

        $contents = File::get($path);
        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function credentialContents(Server $server, string $token): string
    {
        $url = $this->controlPlaneUrl();

        $lines = [
            '# CloudForge bootstrap enrollment credential.',
            '# Contains a single-use token. Source it, then delete it:',
            '#   set -a; . '.$this->credentialPath().'; set +a',
        ];

        $lines[] = 'CLOUDFORGE_CONTROL_PLANE_URL='.$this->shellQuote($url);
        $lines[] = 'CLOUDFORGE_SERVER_ID='.$this->shellQuote($server->id);
        $lines[] = 'CLOUDFORGE_ENROLLMENT_TOKEN='.$this->shellQuote($token);

        return implode("\n", $lines)."\n";
    }

    private function statusContents(Server $server, ?ServerEnrollment $enrollment): string
    {
        $status = [
            'schema_version' => 1,
            'provisioned_at' => Carbon::now()->toIso8601String(),
            'control_plane_url' => $this->controlPlaneUrl(),
            'already_enrolled' => $enrollment === null,
            'credential_path' => $this->credentialPath(),
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'hostname' => $server->hostname,
                'machine_id' => $server->machine_id,
                'is_bootstrap' => $server->isBootstrap(),
                'organization_id' => $server->organization_id,
                'claimed_at' => $server->claimed_at?->toIso8601String(),
            ],
            'enrollment' => $enrollment === null ? null : [
                'id' => $enrollment->id,
                'purpose' => $enrollment->purpose->value,
                'expires_at' => $enrollment->expires_at->toIso8601String(),
            ],
        ];

        return json_encode(
            $status,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);

        File::ensureDirectoryExists($directory, 0700);

        $temporary = tempnam($directory, '.cloudforge-bootstrap-');

        if ($temporary === false) {
            throw new RuntimeException("Unable to create a temporary file in {$directory}.");
        }

        try {
            @chmod($temporary, 0600);

            $handle = fopen($temporary, 'wb');

            if ($handle === false) {
                throw new RuntimeException("Unable to write the bootstrap file in {$directory}.");
            }

            try {
                if (fwrite($handle, $contents) !== strlen($contents)) {
                    throw new RuntimeException("Unable to write the bootstrap file in {$directory}.");
                }

                fflush($handle);

                if (function_exists('fsync')) {
                    @fsync($handle);
                }
            } finally {
                fclose($handle);
            }

            @chmod($temporary, 0600);

            if (! @rename($temporary, $path)) {
                // Windows cannot always replace an existing file atomically.
                @unlink($path);

                if (! @rename($temporary, $path)) {
                    throw new RuntimeException("Unable to move the bootstrap file into place at {$path}.");
                }
            }

            @chmod($path, 0600);
        } finally {
            if (File::exists($temporary)) {
                File::delete($temporary);
            }
        }
    }

    private function controlPlaneUrl(): string
    {
        $url = trim((string) config('cloudforge.bootstrap.control_plane_url', ''));

        return $url !== '' ? rtrim($url, '/') : 'http://localhost';
    }

    private function shellQuote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
