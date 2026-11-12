<?php

namespace Database\Factories;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Enums\EnvironmentType;
use App\Domains\Projects\Models\Environment;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'organization_id' => Organization::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'description' => fake()->sentence(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Project $project): void {
            Environment::query()->firstOrCreate(
                [
                    'project_id' => $project->id,
                    'slug' => 'production',
                ],
                [
                    'organization_id' => $project->organization_id,
                    'name' => 'Production',
                    'type' => EnvironmentType::Production,
                ],
            );
        });
    }
}
