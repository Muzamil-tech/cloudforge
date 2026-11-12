<?php

namespace App\Domains\Resources\Models;

use App\Domains\Infrastructure\Models\Server;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Models\Environment;
use App\Domains\Projects\Models\Project;
use App\Domains\Resources\Enums\ResourceStatus;
use App\Domains\Resources\Enums\ResourceType;
use Database\Factories\ResourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[UseFactory(ResourceFactory::class)]
#[Fillable([
    'organization_id',
    'project_id',
    'environment_id',
    'server_id',
    'name',
    'type',
    'status',
    'image',
    'container_name',
    'volume_name',
    'container_id',
    'host',
    'port',
    'database_name',
    'username',
    'password',
    'credentials_revealed_at',
    'last_command_id',
    'last_error',
    'metadata',
])]
class Resource extends Model
{
    /** @use HasFactory<ResourceFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $hidden = ['password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ResourceType::class,
            'status' => ResourceStatus::class,
            'password' => 'encrypted',
            'port' => 'integer',
            'credentials_revealed_at' => 'datetime',
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

    public function isRevealed(): bool
    {
        return $this->credentials_revealed_at !== null;
    }

    public function canRevealCredentials(): bool
    {
        return $this->status === ResourceStatus::Active
            && $this->password !== null;
    }

    public function canRecoverCredentials(): bool
    {
        return $this->password === null
            && in_array($this->status, [ResourceStatus::Failed, ResourceStatus::Active, ResourceStatus::Stopped], true);
    }
}
