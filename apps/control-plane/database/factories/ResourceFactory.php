<?php

namespace Database\Factories;

use App\Domains\Infrastructure\Models\Server;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Models\Environment;
use App\Domains\Projects\Models\Project;
use App\Domains\Resources\Enums\ResourceStatus;
use App\Domains\Resources\Enums\ResourceType;
use App\Domains\Resources\Models\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<resource>
 */
class ResourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = (string) Str::uuid();
        $type = ResourceType::Postgresql;

        return [
            'organization_id' => Organization::factory(),
            'project_id' => Project::factory(),
            'environment_id' => Environment::factory(),
            'server_id' => Server::factory(),
            'name' => 'db-'.fake()->unique()->numerify('###'),
            'type' => $type,
            'status' => ResourceStatus::Provisioning,
            'image' => $type->image(),
            'container_name' => 'cloudforge-'.$id,
            'volume_name' => 'cloudforge-'.$id.'-data',
            'container_id' => null,
            'host' => '127.0.0.1',
            'port' => null,
            'database_name' => null,
            'username' => null,
            'password' => null,
            'credentials_revealed_at' => null,
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

    public function type(ResourceType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
            'image' => $type->image(),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ResourceStatus::Active,
            'container_id' => 'container-'.Str::random(12),
            'port' => fake()->numberBetween(20000, 30000),
            'database_name' => 'cloudforge',
            'username' => 'cloudforge',
            'password' => Str::random(32),
        ]);
    }
}
