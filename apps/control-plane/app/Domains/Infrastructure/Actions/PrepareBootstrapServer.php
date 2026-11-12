<?php

namespace App\Domains\Infrastructure\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Enums\EnrollmentPurpose;
use App\Domains\Infrastructure\Enums\ServerRoleType;
use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerEnrollment;
use App\Domains\Infrastructure\Models\ServerRole;
use App\Domains\Infrastructure\Services\HostInspector;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Provisions or refreshes the unclaimed server record for the host running the
 * control plane. The operation is idempotent by machine-id: re-running the
 * installer updates host facts and preserves the existing server identity.
 */
class PrepareBootstrapServer
{
    public function __construct(
        private readonly IssueEnrollmentToken $issueEnrollmentToken,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $facts
     * @return array{
     *     server: Server,
     *     enrollment: ServerEnrollment|null,
     *     token: string|null,
     *     already_enrolled: bool,
     *     created: bool
     * }
     */
    public function execute(array $facts, ?string $name = null, ?User $actor = null): array
    {
        $machineId = HostInspector::normalizeMachineId((string) ($facts['machine_id'] ?? ''));

        if ($machineId === null) {
            throw ValidationException::withMessages([
                'machine_id' => 'The host machine-id could not be determined. Set CLOUDFORGE_MACHINE_ID only for hosts without /etc/machine-id.',
            ]);
        }

        $hostname = $this->requiredString($facts, 'hostname');

        if ($hostname === null) {
            throw ValidationException::withMessages([
                'hostname' => 'The host hostname could not be determined.',
            ]);
        }

        $metadata = [
            'addresses' => $this->addressList($facts['addresses'] ?? []),
            'available_disk_bytes' => $this->positiveInt($facts['available_disk_bytes'] ?? null),
            'bootstrap' => [
                'source' => 'cloudforge:bootstrap',
                'inspected_at' => now()->toIso8601String(),
            ],
        ];

        $attributes = array_filter([
            'hostname' => $hostname,
            'public_ip' => $this->ipAddress($facts['public_ip'] ?? null),
            'private_ip' => $this->ipAddress($facts['private_ip'] ?? null),
            'operating_system' => $this->optionalString($facts['operating_system'] ?? null, 255),
            'architecture' => $this->optionalString($facts['architecture'] ?? null, 64),
            'cpu_cores' => $this->positiveInt($facts['cpu_cores'] ?? null),
            'total_memory_bytes' => $this->positiveInt($facts['total_memory_bytes'] ?? null),
            'total_disk_bytes' => $this->positiveInt($facts['total_disk_bytes'] ?? null),
            'docker_version' => $this->optionalString($facts['docker_version'] ?? null, 255),
        ], fn (mixed $value): bool => $value !== null);

        return DB::transaction(function () use ($machineId, $hostname, $name, $metadata, $attributes, $actor): array {
            $server = Server::query()->where('machine_id', $machineId)->first();
            $created = false;

            if ($server === null) {
                $server = Server::query()->create([
                    ...$attributes,
                    'organization_id' => null,
                    'machine_id' => $machineId,
                    'is_bootstrap' => true,
                    'claimed_at' => null,
                    'name' => $this->displayName($name, $hostname),
                    'status' => ServerStatus::InstallationPending,
                    'metadata' => $metadata,
                ]);

                $created = true;
            } else {
                if (! $server->isBootstrap()) {
                    throw ValidationException::withMessages([
                        'machine_id' => 'This machine-id is already linked to a manually registered server.',
                    ]);
                }

                $updates = [
                    ...$attributes,
                    'metadata' => array_merge($server->metadata ?? [], $metadata),
                ];

                if ($name !== null && trim($name) !== '') {
                    $updates['name'] = $this->displayName($name, $server->hostname);
                }

                $server->forceFill($updates)->save();
            }

            $this->ensureRoles($server);

            $this->auditLogger->record(
                AuditAction::ServerBootstrapProvisioned,
                null,
                $server,
                [
                    'created' => $created,
                    'hostname' => $server->hostname,
                    'machine_id_hash' => hash('sha256', $machineId),
                    'docker_detected' => $server->docker_version !== null,
                    'address_count' => count($metadata['addresses']),
                ],
                $actor,
            );

            if ($server->activeCertificate() !== null) {
                return [
                    'server' => $server->refresh()->load('roles'),
                    'enrollment' => null,
                    'token' => null,
                    'already_enrolled' => true,
                    'created' => $created,
                ];
            }

            if ($server->organization_id !== null) {
                throw ValidationException::withMessages([
                    'server' => 'This bootstrap server is already claimed. Issue a new enrollment token from the dashboard instead.',
                ]);
            }

            $ttlMinutes = max(1, (int) config('cloudforge.bootstrap.token_ttl_minutes', 30));

            $result = $this->issueEnrollmentToken->execute(
                $server,
                $actor,
                EnrollmentPurpose::Bootstrap,
                now()->addMinutes($ttlMinutes),
            );

            return [
                'server' => $result['enrollment']->server->refresh()->load('roles'),
                'enrollment' => $result['enrollment'],
                'token' => $result['token'],
                'already_enrolled' => false,
                'created' => $created,
            ];
        });
    }

    private function ensureRoles(Server $server): void
    {
        foreach ([ServerRoleType::Control, ServerRoleType::Application] as $role) {
            ServerRole::query()->firstOrCreate([
                'server_id' => $server->id,
                'role' => $role,
            ]);
        }
    }

    private function displayName(?string $name, string $hostname): string
    {
        $candidate = $name ?? config('cloudforge.bootstrap.server_name') ?? $hostname;
        $candidate = trim((string) $candidate);

        if ($candidate === '') {
            $candidate = $hostname;
        }

        return Str::limit($candidate, 120, '');
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function requiredString(array $facts, string $key): ?string
    {
        $value = $facts[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function optionalString(mixed $value, int $limit): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Str::limit(trim($value), $limit, '');
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function ipAddress(mixed $value): ?string
    {
        if (! is_string($value) || filter_var(trim($value), FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return trim($value);
    }

    /**
     * @return list<string>
     */
    private function addressList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $addresses = [];

        foreach ($value as $address) {
            if (is_string($address) && filter_var(trim($address), FILTER_VALIDATE_IP) !== false) {
                $addresses[trim($address)] = true;
            }
        }

        $addresses = array_keys($addresses);
        sort($addresses);

        return $addresses;
    }
}
