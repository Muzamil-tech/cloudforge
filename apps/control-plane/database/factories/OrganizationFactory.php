<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Enums\OrganizationRole;
use App\Domains\Organizations\Models\Membership;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'owner_user_id' => User::factory(),
            'plan' => 'free',
            'status' => 'active',
            'settings' => [],
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Organization $organization): void {
            Membership::query()->firstOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $organization->owner_user_id,
                ],
                [
                    'role' => OrganizationRole::Owner,
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                ],
            );
        });
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'owner_user_id' => $user->id,
        ]);
    }
}
