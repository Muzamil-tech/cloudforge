<?php

namespace App\Domains\Applications\Models;

use App\Domains\Applications\Enums\DeploymentStatus;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Models\Environment;
use App\Domains\Projects\Models\Project;
use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(DeploymentFactory::class)]
#[Fillable([
    'organization_id',
    'project_id',
    'environment_id',
    'application_id',
    'server_id',
    'image',
    'container_name',
    'container_id',
    'host_port',
    'status',
    'command_id',
    'logs',
    'error_message',
    'started_at',
    'finished_at',
    'metadata',
])]
class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'host_port' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return HasMany<DeploymentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(DeploymentEvent::class)->orderBy('created_at');
    }

    public function isDeploying(): bool
    {
        return $this->status === DeploymentStatus::Deploying;
    }

    public function isActive(): bool
    {
        return $this->status === DeploymentStatus::Active;
    }
}
