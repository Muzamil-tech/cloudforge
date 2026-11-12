<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    use InteractsWithOrganizations, RefreshDatabase;

    public function test_health_endpoint_reports_the_application_is_up(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('servers.index'))->assertRedirect(route('login'));
    }

    public function test_api_requests_without_a_token_receive_json_errors(): void
    {
        $this->getJson(route('api.servers.index'))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_authenticated_users_without_an_organization_are_sent_to_onboarding(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('onboarding'));
    }

    public function test_authenticated_users_land_on_the_dashboard_when_an_organization_is_selected(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);

        $this->actingIn($organization, $owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('currentOrganization.id', $organization->id));
    }

    public function test_organizations_are_created_with_the_creator_as_owner(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('onboarding.store'), ['name' => 'Acme Cloud'])
            ->assertRedirect(route('dashboard'));

        $organization = Organization::query()->sole();

        $this->assertSame($user->id, $organization->owner_user_id);
        $this->assertSame('acme-cloud', $organization->slug);
        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
    }
}
