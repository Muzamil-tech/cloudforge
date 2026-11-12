<?php

namespace Tests\Concerns;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Enums\OrganizationRole;
use App\Domains\Organizations\Models\Membership;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\OrganizationApiToken;
use Illuminate\Support\Str;

trait InteractsWithOrganizations
{
    protected function organizationOwnedBy(?User $owner = null): Organization
    {
        $owner ??= User::factory()->create();

        return Organization::factory()->ownedBy($owner)->create();
    }

    protected function memberWithRole(
        Organization $organization,
        OrganizationRole $role,
        ?User $user = null,
    ): User {
        $user ??= User::factory()->create();

        Membership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return $user;
    }

    /**
     * Authenticate the user for web requests and select the organization for
     * the duration of the test.
     */
    protected function actingIn(Organization $organization, User $user): static
    {
        return $this->actingAs($user)->withSession([
            'current_organization_id' => $organization->id,
        ]);
    }

    /**
     * Create a plaintext organization API token and return the bearer value.
     *
     * @param  list<string>  $abilities
     */
    protected function issueApiToken(
        Organization $organization,
        array $abilities = ['*'],
        ?\DateTimeInterface $expiresAt = null,
        ?User $actor = null,
    ): string {
        $plaintext = 'cf_'.Str::random(48);

        OrganizationApiToken::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Test token',
            'token_hash' => OrganizationApiToken::hashToken($plaintext),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
            'created_by_user_id' => $actor?->id,
        ]);

        return $plaintext;
    }

    /**
     * @return array<string, string>
     */
    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
