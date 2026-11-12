<?php

namespace App\Domains\Infrastructure\Models;

use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Operations\Models\AgentCommand;
use App\Domains\Organizations\Models\Organization;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[UseFactory(ServerFactory::class)]
#[Fillable([
    'organization_id',
    'machine_id',
    'is_bootstrap',
    'claimed_at',
    'name',
    'hostname',
    'public_ip',
    'private_ip',
    'region',
    'operating_system',
    'architecture',
    'cpu_cores',
    'total_memory_bytes',
    'total_disk_bytes',
    'docker_version',
    'agent_version',
    'status',
    'last_heartbeat_at',
    'last_inventory_at',
    'metadata',
])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ServerStatus::class,
            'metadata' => 'array',
            'is_bootstrap' => 'boolean',
            'claimed_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'last_inventory_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<ServerRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(ServerRole::class);
    }

    /**
     * @return HasMany<ServerCapability, $this>
     */
    public function capabilities(): HasMany
    {
        return $this->hasMany(ServerCapability::class);
    }

    /**
     * @return HasMany<ServerEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(ServerEnrollment::class);
    }

    /**
     * @return HasMany<ServerCertificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(ServerCertificate::class);
    }

    /**
     * @return HasMany<ServerMetric, $this>
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(ServerMetric::class);
    }

    /**
     * @return HasMany<AgentCommand, $this>
     */
    public function commands(): HasMany
    {
        return $this->hasMany(AgentCommand::class);
    }

    /**
     * @return list<string>
     */
    public function roleNames(): array
    {
        return $this->roles->pluck('role')->map(fn ($role) => $role->value)->values()->all();
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roleNames(), true);
    }

    public function isReachable(): bool
    {
        return $this->status->isReachable();
    }

    public function isBootstrap(): bool
    {
        return (bool) $this->is_bootstrap;
    }

    public function isClaimed(): bool
    {
        return $this->organization_id !== null && $this->claimed_at !== null;
    }

    public function isUnclaimedBootstrap(): bool
    {
        return $this->isBootstrap() && $this->organization_id === null;
    }

    public function activeCertificate(): ?ServerCertificate
    {
        return $this->certificates()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('issued_at')
            ->first();
    }

    public function latestMetric(): ?ServerMetric
    {
        return $this->metrics()->latest('recorded_at')->first();
    }
}
