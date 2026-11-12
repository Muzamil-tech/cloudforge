<?php

namespace App\Domains\Applications\Models;

use App\Domains\Applications\Enums\ApplicationStatus;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Models\Environment;
use App\Domains\Projects\Models\Project;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[UseFactory(ApplicationFactory::class)]
#[Fillable([
    'organization_id',
    'project_id',
    'environment_id',
    'server_id',
    'name',
    'slug',
    'image',
    'container_name',
    'container_id',
    'container_port',
    'host',
    'host_port',
    'health_path',
    'status',
    'last_command_id',
    'last_error',
    'metadata',
])]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'container_port' => 'integer',
            'host_port' => 'integer',
            'metadata' => 'array',
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
     * @return HasMany<Deployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /**
     * @return HasOne<Deployment, $this>
     */
    public function latestDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->latestOfMany('created_at');
    }

    public function isTransitional(): bool
    {
        return $this->status->isTransitional();
    }

    public function canStart(): bool
    {
        return $this->status->canStart() && $this->container_id !== null;
    }

    public function canStop(): bool
    {
        return $this->status->canStop();
    }

    public function canDeploy(): bool
    {
        return $this->status->canDeploy();
    }

    public function canDelete(): bool
    {
        return $this->status->canDelete();
    }

    public function canRefresh(): bool
    {
        return $this->status->canRefresh();
    }
}
