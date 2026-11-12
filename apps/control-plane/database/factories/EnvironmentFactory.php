<?php

namespace Database\Factories;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Enums\EnvironmentType;
use App\Domains\Projects\Models\Environment;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Environment>
 */
class EnvironmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'project_id' => Project::factory(),
            'name' => 'Staging',
            'slug' => 'staging-'.fake()->unique()->numerify('###'),
            'type' => EnvironmentType::Staging,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);
    }
}
