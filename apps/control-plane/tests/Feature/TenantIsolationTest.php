<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Models\AuditLog;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Enums\OrganizationRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use InteractsWithOrganizations, RefreshDatabase;

    public function test_users_cannot_view_servers_belonging_to_another_organization(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $otherOrganization = $this->organizationOwnedBy(User::factory()->create());

        $otherServer = Server::factory()->forOrganization($otherOrganization)->create();

        $this->actingIn($organization, $owner)
            ->get(route('servers.show', $otherServer))
            ->assertForbidden();

        $this->actingIn($organization, $owner)
            ->delete(route('servers.destroy', $otherServer))
            ->assertForbidden();

        $this->assertDatabaseHas('servers', ['id' => $otherServer->id, 'deleted_at' => null]);
    }

    public function test_server_index_only_contains_servers_from_the_current_organization(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $otherOrganization = $this->organizationOwnedBy(User::factory()->create());

        $ownServer = Server::factory()->forOrganization($organization)->create();
        Server::factory()->forOrganization($otherOrganization)->create();

        $this->actingIn($organization, $owner)
            ->get(route('servers.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Servers/Index')
                ->has('servers.data', 1)
                ->where('servers.data.0.id', $ownServer->id));
    }

    public function test_users_cannot_view_or_delete_projects_belonging_to_another_organization(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $otherOrganization = $this->organizationOwnedBy(User::factory()->create());

        $otherProject = Project::factory()->create(['organization_id' => $otherOrganization->id]);

        $this->actingIn($organization, $owner)
            ->get(route('projects.show', $otherProject))
            ->assertForbidden();

        $this->actingIn($organization, $owner)
            ->delete(route('projects.destroy', $otherProject))
            ->assertForbidden();

        $this->assertDatabaseHas('projects', ['id' => $otherProject->id, 'deleted_at' => null]);
    }

    public function test_project_index_only_contains_projects_from_the_current_organization(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $otherOrganization = $this->organizationOwnedBy(User::factory()->create());

        $ownProject = Project::factory()->create(['organization_id' => $organization->id]);
        Project::factory()->create(['organization_id' => $otherOrganization->id]);

        $this->actingIn($organization, $owner)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Projects/Index')
                ->has('projects', 1)
                ->where('projects.0.id', $ownProject->id));
    }

    public function test_membership_records_from_another_organization_cannot_be_modified(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $otherOrganization = $this->organizationOwnedBy(User::factory()->create());
        $otherMember = $this->memberWithRole($otherOrganization, OrganizationRole::Viewer);

        $membership = $otherOrganization->memberships()->where('user_id', $otherMember->id)->sole();

        $this->actingIn($organization, $owner)
            ->patch(route('members.update', $membership), [
                'role' => OrganizationRole::Developer->value,
            ])
            ->assertNotFound();

        $this->actingIn($organization, $owner)
            ->delete(route('members.destroy', $membership))
            ->assertNotFound();

        $this->assertDatabaseHas('organization_members', ['id' => $membership->id]);
    }

    public function test_users_cannot_switch_into_an_organization_they_do_not_belong_to(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $otherOrganization = $this->organizationOwnedBy(User::factory()->create());

        $this->actingIn($organization, $owner)
            ->post(route('organizations.switch', $otherOrganization))
            ->assertForbidden();

        $this->assertSame($organization->id, session('current_organization_id'));
    }

    public function test_users_can_switch_to_another_organization_they_belong_to(): void
    {
        $user = User::factory()->create();
        $organization = $this->organizationOwnedBy($user);
        $secondOrganization = Organization::factory()->create();
        $this->memberWithRole($secondOrganization, OrganizationRole::Viewer, $user);

        $this->actingIn($organization, $user)
            ->post(route('organizations.switch', $secondOrganization))
            ->assertRedirect(route('dashboard'));

        $this->assertSame($secondOrganization->id, session('current_organization_id'));
    }

    public function test_suspended_memberships_do_not_grant_access(): void
    {
        $user = User::factory()->create();
        $organization = $this->organizationOwnedBy($user);

        $organization->memberships()
            ->where('user_id', $user->id)
            ->update(['status' => MembershipStatus::Suspended->value]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('onboarding'));

        $this->actingAs($user)
            ->get(route('servers.index'))
            ->assertRedirect(route('onboarding'));
    }

    public function test_api_tokens_only_list_resources_from_their_own_organization(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $otherOrganization = $this->organizationOwnedBy(User::factory()->create());

        $server = Server::factory()->forOrganization($organization)->create();
        Server::factory()->forOrganization($otherOrganization)->create();

        $project = Project::factory()->create(['organization_id' => $organization->id]);
        Project::factory()->create(['organization_id' => $otherOrganization->id]);

        $token = $this->issueApiToken($organization, ['servers:read', 'projects:read']);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('api.servers.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $server->id);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('api.projects.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $project->id);
    }

    public function test_api_tokens_cannot_read_update_or_delete_cross_organization_resources(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $otherOrganization = $this->organizationOwnedBy(User::factory()->create());

        $otherServer = Server::factory()->forOrganization($otherOrganization)->create();
        $otherProject = Project::factory()->create(['organization_id' => $otherOrganization->id]);

        $token = $this->issueApiToken($organization, ['*']);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('api.servers.show', $otherServer))
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->patchJson(route('api.servers.update', $otherServer), ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->deleteJson(route('api.servers.destroy', $otherServer))
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->getJson(route('api.projects.show', $otherProject))
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->postJson(route('api.servers.enrollment', $otherServer))
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->postJson(route('api.servers.commands.store', $otherServer), [
                'operation' => 'server.inspect',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('servers', ['id' => $otherServer->id, 'name' => $otherServer->name]);
        $this->assertDatabaseHas('projects', ['id' => $otherProject->id, 'deleted_at' => null]);
    }

    public function test_api_audit_logs_are_scoped_to_the_token_organization(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $otherOrganization = $this->organizationOwnedBy(User::factory()->create());

        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'action' => AuditAction::ServerCreated->value,
            'resource_type' => 'Server',
            'metadata' => ['scope' => 'own'],
            'created_at' => now(),
        ]);

        AuditLog::query()->create([
            'organization_id' => $otherOrganization->id,
            'action' => AuditAction::ServerDeleted->value,
            'resource_type' => 'Server',
            'metadata' => ['scope' => 'other'],
            'created_at' => now(),
        ]);

        $token = $this->issueApiToken($organization, ['audit:read']);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson(route('api.audit-logs.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame(AuditAction::ServerCreated->value, $response->json('data.0.action'));
        $this->assertSame('own', $response->json('data.0.metadata.scope'));
    }

    public function test_cross_organization_environment_deletion_is_rejected(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $otherOrganization = $this->organizationOwnedBy(User::factory()->create());

        $otherProject = Project::factory()->create(['organization_id' => $otherOrganization->id]);
        $environment = $otherProject->environments()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Staging',
            'slug' => 'staging',
            'type' => 'staging',
        ]);

        $this->actingIn($organization, $owner)
            ->delete(route('projects.environments.destroy', [$otherProject, $environment]))
            ->assertForbidden();

        $this->assertDatabaseHas('environments', ['id' => $environment->id]);
    }
}
