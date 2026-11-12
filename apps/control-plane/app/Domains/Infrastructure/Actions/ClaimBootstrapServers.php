<?php

namespace App\Domains\Infrastructure\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ClaimBootstrapServers
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Claim unclaimed bootstrap servers for the organization. Claiming is
     * idempotent: servers already owned by any organization are never moved.
     *
     * @return Collection<int, Server>
     */
    public function execute(Organization $organization, ?User $actor = null, ?Server $server = null): Collection
    {
        return DB::transaction(function () use ($organization, $actor, $server): Collection {
            /** @var Collection<int, Server> $servers */
            $servers = Server::query()
                ->where('is_bootstrap', true)
                ->whereNull('organization_id')
                ->when($server !== null, fn ($query) => $query->whereKey($server->id))
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            foreach ($servers as $server) {
                $server->forceFill([
                    'organization_id' => $organization->id,
                    'claimed_at' => now(),
                ])->save();

                $this->auditLogger->record(
                    AuditAction::ServerBootstrapClaimed,
                    $organization,
                    $server,
                    ['source' => 'organization_created'],
                    $actor,
                );
            }

            return $servers;
        });
    }
}
