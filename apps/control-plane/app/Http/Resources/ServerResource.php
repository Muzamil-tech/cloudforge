<?php

namespace App\Http\Resources;

use App\Domains\Infrastructure\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Server
 */
class ServerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'hostname' => $this->hostname,
            'public_ip' => $this->public_ip,
            'private_ip' => $this->private_ip,
            'region' => $this->region,
            'operating_system' => $this->operating_system,
            'architecture' => $this->architecture,
            'cpu_cores' => $this->cpu_cores,
            'total_memory_bytes' => $this->total_memory_bytes,
            'total_disk_bytes' => $this->total_disk_bytes,
            'docker_version' => $this->docker_version,
            'agent_version' => $this->agent_version,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('role')->map->value->all()),
            'capabilities' => $this->whenLoaded('capabilities', fn () => $this->capabilities->map(fn ($capability) => [
                'name' => $capability->capability,
                'version' => $capability->version,
                'metadata' => $capability->metadata,
            ])->all()),
            'last_heartbeat_at' => $this->last_heartbeat_at?->toIso8601String(),
            'last_inventory_at' => $this->last_inventory_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
