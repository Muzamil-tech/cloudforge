<?php

namespace App\Domains\Infrastructure\Services;

use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerCapability;
use Illuminate\Support\Facades\DB;

class RecordInventory
{
    /**
     * @param  array<string, mixed>  $inventory
     */
    public function execute(Server $server, array $inventory): Server
    {
        return DB::transaction(function () use ($server, $inventory): Server {
            /** @var Server $locked */
            $locked = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();

            $attributes = array_filter([
                'hostname' => $inventory['hostname'] ?? null,
                'operating_system' => $inventory['operating_system'] ?? null,
                'architecture' => $inventory['architecture'] ?? null,
                'cpu_cores' => $inventory['cpu_cores'] ?? null,
                'total_memory_bytes' => $inventory['total_memory_bytes'] ?? null,
                'total_disk_bytes' => $inventory['total_disk_bytes'] ?? null,
                'docker_version' => $inventory['docker_version'] ?? null,
                'agent_version' => $inventory['agent_version'] ?? null,
            ], fn (mixed $value): bool => $value !== null && $value !== '');

            $metadata = $locked->metadata ?? [];
            $metadata['inventory'] = [
                'collected_at' => $inventory['collected_at'] ?? now()->toIso8601String(),
                'docker' => $inventory['docker'] ?? null,
            ];

            $attributes['metadata'] = $metadata;
            $attributes['last_inventory_at'] = now();

            $locked->forceFill($attributes)->save();

            $capabilities = collect($inventory['capabilities'] ?? [])
                ->filter(fn (mixed $capability): bool => is_array($capability) && is_string($capability['name'] ?? null))
                ->unique('name');

            ServerCapability::query()->where('server_id', $locked->id)->delete();

            foreach ($capabilities as $capability) {
                ServerCapability::query()->create([
                    'server_id' => $locked->id,
                    'capability' => (string) $capability['name'],
                    'version' => isset($capability['version']) ? (string) $capability['version'] : null,
                    'metadata' => is_array($capability['metadata'] ?? null) ? $capability['metadata'] : null,
                ]);
            }

            return $locked;
        });
    }
}
