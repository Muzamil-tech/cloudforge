<?php

namespace App\Domains\Infrastructure\Actions;

use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerCertificate;
use App\Domains\Infrastructure\Services\AgentCertificateAuthority;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RotateServerCertificate
{
    public function __construct(
        private readonly AgentCertificateAuthority $authority,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{certificate: ServerCertificate, ca_certificate: string}
     */
    public function execute(Server $server, string $csrPem): array
    {
        try {
            $issued = $this->authority->signCsr($csrPem, $server->id);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'csr' => 'The certificate signing request is invalid or does not match this server.',
            ]);
        }

        return DB::transaction(function () use ($server, $issued): array {
            $certificate = ServerCertificate::query()->create([
                'server_id' => $server->id,
                'serial' => $issued->serial,
                'fingerprint_sha256' => $issued->fingerprint,
                'certificate_pem' => $issued->certificatePem,
                'issued_at' => now(),
                'expires_at' => $issued->expiresAt,
            ]);

            ServerCertificate::query()
                ->where('server_id', $server->id)
                ->where('id', '!=', $certificate->id)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revocation_reason' => 'rotated',
                ]);

            $this->auditLogger->record(
                AuditAction::ServerCertificateRotated,
                $server->organization,
                $server,
                ['fingerprint' => $issued->fingerprint],
            );

            return [
                'certificate' => $certificate,
                'ca_certificate' => $this->authority->certificatePem(),
            ];
        });
    }
}
