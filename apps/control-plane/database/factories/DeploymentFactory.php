<?php

namespace Database\Factories;

use App\Domains\Applications\Enums\DeploymentStatus;
use App\Domains\Applications\Models\Application;
use App\Domains\Applications\Models\Deployment;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Models\Environment;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Deployment>
 */
class DeploymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = (string) Str::uuid();

        return [
            'organization_id' => Organization::factory(),
            'project_id' => Project::factory(),
            'environment_id' => Environment::factory(),
            'application_id' => Application::factory(),
            'server_id' => Server::factory(),
            'image' => 'nginx:1.27-alpine',
            'container_name' => 'cloudforge-app-'.$id,
            'container_id' => null,
            'host_port' => null,
            'status' => DeploymentStatus::Deploying,
            'command_id' => null,
            'logs' => null,
            'error_message' => null,
            'started_at' => now(),
            'finished_at' => null,
            'metadata' => [],
        ];
    }

    public function forApplication(Application $application): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $application->organization_id,
            'project_id' => $application->project_id,
            'environment_id' => $application->environment_id,
            'application_id' => $application->id,
            'server_id' => $application->server_id,
            'image' => $application->image,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DeploymentStatus::Active,
            'container_id' => 'container-'.Str::random(12),
            'host_port' => fake()->numberBetween(20000, 30000),
            'finished_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DeploymentStatus::Failed,
            'error_message' => 'The container exited before it became healthy.',
            'finished_at' => now(),
        ]);
    }
}
