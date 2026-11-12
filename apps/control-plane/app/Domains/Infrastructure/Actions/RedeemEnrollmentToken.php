<?php

namespace App\Domains\Infrastructure\Actions;

use App\Domains\Infrastructure\Enums\ServerRoleType;
use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerCertificate;
use App\Domains\Infrastructure\Models\ServerEnrollment;
use App\Domains\Infrastructure\Models\ServerRole;
use App\Domains\Infrastructure\Services\AgentCertificateAuthority;
use App\Domains\Infrastructure\ValueObjects\IssuedCertificate;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RedeemEnrollmentToken
{
    private const INVALID_TOKEN_MESSAGE = 'The enrollment token is invalid, expired, or has already been used.';

    public function __construct(
        private readonly AgentCertificateAuthority $authority,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $agent
     * @return array{certificate: ServerCertificate, server: Server, ca_certificate: string}
     */
    public function execute(string $token, string $csrPem, array $agent): array
    {
        $enrollment = ServerEnrollment::query()
            ->with('server.organization')
            ->where('token_hash', ServerEnrollment::hashToken($token))
            ->first();

        if ($enrollment === null || ! $enrollment->isUsable()) {
            throw ValidationException::withMessages(['token' => self::INVALID_TOKEN_MESSAGE]);
        }

        $server = $enrollment->server;

        if ($server === null || $server->trashed()) {
            throw ValidationException::withMessages(['token' => self::INVALID_TOKEN_MESSAGE]);
        }

        $this->assertBootstrapMachineMatches($enrollment, $server, $agent);

        try {
            $issued = $this->authority->signCsr($csrPem, $server->id);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'csr' => 'The certificate signing request is invalid or does not match this server.',
            ]);
        }

        return DB::transaction(function () use ($enrollment, $agent, $issued): array {
            /** @var ServerEnrollment $locked */
            $locked = ServerEnrollment::query()
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isUsable()) {
                throw ValidationException::withMessages(['token' => self::INVALID_TOKEN_MESSAGE]);
            }

            /** @var Server $server */
            $server = Server::query()
                ->whereKey($locked->server_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertBootstrapMachineMatches($locked, $server, $agent);

            $locked->forceFill(['consumed_at' => now()])->save();

            ServerEnrollment::query()
                ->where('server_id', $server->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $this->claimWhenSingleOrganizationExists($locked, $server);

            $certificate = $this->persistCertificate($server, $issued);

            $server->forceFill([
                'hostname' => $this->stringOr($agent['hostname'] ?? null, $server->hostname),
                'operating_system' => $this->stringOr($agent['operating_system'] ?? null, $server->operating_system),
                'architecture' => $this->stringOr($agent['architecture'] ?? null, $server->architecture),
                'agent_version' => $this->stringOr($agent['agent_version'] ?? null, $server->agent_version),
                'docker_version' => $this->stringOr($agent['docker_version'] ?? null, $server->docker_version),
                'status' => ServerStatus::AgentRegistered,
                'last_heartbeat_at' => null,
            ])->save();

            if (! $server->roles()->exists()) {
                ServerRole::query()->create([
                    'server_id' => $server->id,
                    'role' => ServerRoleType::General,
                ]);
            }

            $server->refresh()->load('organization');

            $this->auditLogger->record(
                AuditAction::ServerEnrolled,
                $server->organization,
                $server,
                [
                    'purpose' => $locked->purpose->value,
                    'fingerprint' => $issued->fingerprint,
                    'expires_at' => $issued->expiresAt->toIso8601String(),
                ],
            );

            return [
                'certificate' => $certificate,
                'server' => $server,
                'ca_certificate' => $this->authority->certificatePem(),
            ];
        });
    }

    /**
     * Bootstrap credentials are scoped to the physical host that generated
     * them. Installation credentials are operator-issued for a named server.
     *
     * @param  array<string, mixed>  $agent
     */
    private function assertBootstrapMachineMatches(ServerEnrollment $enrollment, Server $server, array $agent): void
    {
        if (! $enrollment->isBootstrap()) {
            return;
        }

        $presented = $agent['machine_id'] ?? null;

        if ($server->machine_id === null
            || ! is_string($presented)
            || trim($presented) === ''
            || ! hash_equals($server->machine_id, trim($presented))) {
            throw ValidationException::withMessages([
                'machine_id' => 'This bootstrap enrollment token is not valid for this host.',
            ]);
        }
    }

    private function claimWhenSingleOrganizationExists(ServerEnrollment $enrollment, Server $server): void
    {
        if (! $enrollment->isBootstrap() || $server->organization_id !== null) {
            return;
        }

        if (Organization::query()->count() !== 1) {
            return;
        }

        /** @var Organization $organization */
        $organization = Organization::query()->firstOrFail();

        $server->forceFill([
            'organization_id' => $organization->id,
            'claimed_at' => now(),
        ])->save();

        $this->auditLogger->record(
            AuditAction::ServerBootstrapClaimed,
            $organization,
            $server,
            ['source' => 'enrollment'],
        );
    }

    private function persistCertificate(Server $server, IssuedCertificate $issued): ServerCertificate
    {
        return ServerCertificate::query()->create([
            'server_id' => $server->id,
            'serial' => $issued->serial,
            'fingerprint_sha256' => $issued->fingerprint,
            'certificate_pem' => $issued->certificatePem,
            'issued_at' => now(),
            'expires_at' => $issued->expiresAt,
        ]);
    }

    private function stringOr(mixed $value, ?string $fallback): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    }
}
