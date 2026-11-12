<?php

namespace Database\Factories;

use App\Domains\Applications\Enums\ApplicationStatus;
use App\Domains\Applications\Models\Application;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Models\Environment;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = (string) Str::uuid();
        $name = 'app-'.fake()->unique()->numerify('###');

        return [
            'organization_id' => Organization::factory(),
            'project_id' => Project::factory(),
            'environment_id' => Environment::factory(),
            'server_id' => Server::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'image' => 'nginx:1.27-alpine',
            'container_name' => 'cloudforge-app-'.$id,
            'container_id' => null,
            'container_port' => 80,
            'host' => '127.0.0.1',
            'host_port' => null,
            'health_path' => '/',
            'status' => ApplicationStatus::Deploying,
            'last_command_id' => null,
            'last_error' => null,
            'metadata' => [],
        ];
    }

    public function forProject(Project $project, Environment $environment, Server $server): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'environment_id' => $environment->id,
            'server_id' => $server->id,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ApplicationStatus::Active,
            'container_id' => 'container-'.Str::random(12),
            'host_port' => fake()->numberBetween(20000, 30000),
        ]);
    }

    public function image(string $image): static
    {
        return $this->state(fn (array $attributes): array => [
            'image' => $image,
        ]);
    }
}
