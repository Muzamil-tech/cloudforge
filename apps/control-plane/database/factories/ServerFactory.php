<?php

namespace Database\Factories;

use App\Domains\Infrastructure\Enums\ServerRoleType;
use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerRole;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'node-'.fake()->unique()->numerify('###'),
            'hostname' => fake()->unique()->domainName(),
            'public_ip' => fake()->ipv4(),
            'private_ip' => '10.80.0.'.fake()->numberBetween(2, 250),
            'region' => 'fsn1',
            'operating_system' => 'Ubuntu 24.04',
            'architecture' => 'x86_64',
            'cpu_cores' => 4,
            'total_memory_bytes' => 8 * 1024 ** 3,
            'total_disk_bytes' => 80 * 1024 ** 3,
            'docker_version' => '27.3.1',
            'agent_version' => '1.0.0',
            'status' => ServerStatus::InstallationPending,
            'metadata' => [],
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Server $server): void {
            if ($server->roles()->doesntExist()) {
                ServerRole::query()->create([
                    'server_id' => $server->id,
                    'role' => ServerRoleType::Application,
                    'created_at' => now(),
                ]);
            }
        });
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $organization->id,
        ]);
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ServerStatus::Ready,
            'last_heartbeat_at' => now(),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ServerStatus::Active,
            'last_heartbeat_at' => now(),
        ]);
    }

    public function unreachable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ServerStatus::Unreachable,
            'last_heartbeat_at' => now()->subMinutes(10),
        ]);
    }

    public function draining(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ServerStatus::Draining,
            'last_heartbeat_at' => now(),
        ]);
    }

    public function withRole(ServerRoleType $role): static
    {
        return $this->afterCreating(function (Server $server) use ($role): void {
            ServerRole::query()->firstOrCreate(
                ['server_id' => $server->id, 'role' => $role],
                ['created_at' => now()],
            );
        });
    }

    public function hostnameSlug(): static
    {
        return $this->state(fn (array $attributes): array => [
            'hostname' => Str::slug($attributes['name']).'.internal',
        ]);
    }
}
