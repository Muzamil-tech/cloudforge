<?php

namespace App\Domains\Projects\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Enums\EnvironmentType;
use App\Domains\Projects\Models\Environment;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateProject
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{name: string, description?: string|null}  $attributes
     * @param  list<array{name: string, type: EnvironmentType|string}>  $environments
     */
    public function execute(
        Organization $organization,
        User $actor,
        array $attributes,
        array $environments = [],
    ): Project {
        return DB::transaction(function () use ($organization, $actor, $attributes, $environments): Project {
            $project = Project::query()->create([
                'organization_id' => $organization->id,
                'name' => $attributes['name'],
                'slug' => $this->uniqueSlug($organization, $attributes['name']),
                'description' => $attributes['description'] ?? null,
            ]);

            foreach ($environments === [] ? [['name' => 'Production', 'type' => EnvironmentType::Production]] : $environments as $environment) {
                Environment::query()->create([
                    'organization_id' => $organization->id,
                    'project_id' => $project->id,
                    'name' => $environment['name'],
                    'slug' => $this->uniqueEnvironmentSlug($organization, $environment['name']),
                    'type' => $environment['type'] instanceof EnvironmentType
                        ? $environment['type']
                        : EnvironmentType::from($environment['type']),
                ]);
            }

            $this->auditLogger->record(
                AuditAction::ProjectCreated,
                $organization,
                $project,
                ['name' => $project->name],
                $actor,
            );

            return $project;
        });
    }

    public function addEnvironment(
        Project $project,
        Organization $organization,
        User $actor,
        string $name,
        EnvironmentType $type,
    ): Environment {
        return DB::transaction(function () use ($project, $organization, $actor, $name, $type): Environment {
            $environment = Environment::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'name' => $name,
                'slug' => $this->uniqueEnvironmentSlug($organization, $name),
                'type' => $type,
            ]);

            $this->auditLogger->record(
                AuditAction::ProjectUpdated,
                $organization,
                $project,
                ['added_environment' => $environment->name],
                $actor,
            );

            return $environment;
        });
    }

    private function uniqueSlug(Organization $organization, string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        $suffix = 2;

        while ($organization->projects()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function uniqueEnvironmentSlug(Organization $organization, string $name): string
    {
        $base = Str::slug($name) ?: 'environment';
        $slug = $base;
        $suffix = 2;

        while (Environment::query()->where('organization_id', $organization->id)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
