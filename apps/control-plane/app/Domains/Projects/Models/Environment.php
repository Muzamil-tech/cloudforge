<?php

namespace App\Domains\Projects\Models;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Enums\EnvironmentType;
use App\Domains\Resources\Models\Resource;
use Database\Factories\EnvironmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(EnvironmentFactory::class)]
#[Fillable(['organization_id', 'project_id', 'name', 'slug', 'type'])]
class Environment extends Model
{
    /** @use HasFactory<EnvironmentFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EnvironmentType::class,
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
     * @return HasMany<resource, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }
}
