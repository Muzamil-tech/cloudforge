<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Operations\Enums\AgentCommandStatus;
use App\Domains\Operations\Enums\AgentOperation;
use App\Domains\Operations\Models\AgentCommand;
use App\Domains\Organizations\Enums\OrganizationRole;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use InteractsWithOrganizations, RefreshDatabase;

    public function test_viewers_can_read_but_cannot_mutate_anything(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $viewer = $this->memberWithRole($organization, OrganizationRole::Viewer);
        $server = Server::factory()->forOrganization($organization)->ready()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->actingIn($organization, $viewer)->get(route('servers.index'))->assertOk();
        $this->actingIn($organization, $viewer)->get(route('servers.show', $server))->assertOk();
        $this->actingIn($organization, $viewer)->get(route('projects.show', $project))->assertOk();
        $this->actingIn($organization, $viewer)->get(route('members.index'))->assertOk();
        $this->actingIn($organization, $viewer)->get(route('api-tokens.index'))->assertOk();

        $this->actingIn($organization, $viewer)->get(route('audit-log.index'))->assertForbidden();
        $this->actingIn($organization, $viewer)->get(route('organization.settings'))->assertForbidden();
        $this->actingIn($organization, $viewer)->get(route('servers.create'))->assertForbidden();

        $this->actingIn($organization, $viewer)
            ->post(route('servers.store'), [
                'name' => 'viewer-node',
                'hostname' => 'viewer.example.com',
                'roles' => ['application'],
            ])
            ->assertForbidden();

        $this->actingIn($organization, $viewer)
            ->post(route('servers.enrollment', $server))
            ->assertForbidden();

        $this->actingIn($organization, $viewer)
            ->post(route('servers.certificates.revoke', $server), ['reason' => 'compromised'])
            ->assertForbidden();

        $this->actingIn($organization, $viewer)
            ->post(route('servers.state', $server), [
                'status' => ServerStatus::Maintenance->value,
                'confirm' => true,
            ])
            ->assertForbidden();

        $this->actingIn($organization, $viewer)
            ->post(route('servers.commands.store', $server), [
                'operation' => AgentOperation::ServerInspect->value,
            ])
            ->assertForbidden();

        $this->actingIn($organization, $viewer)
            ->post(route('projects.store'), ['name' => 'Viewer project'])
            ->assertForbidden();

        $this->actingIn($organization, $viewer)
            ->patch(route('projects.update', $project), ['name' => 'Renamed'])
            ->assertForbidden();

        $this->actingIn($organization, $viewer)
            ->delete(route('projects.destroy', $project))
            ->assertForbidden();

        $this->actingIn($organization, $viewer)
            ->post(route('members.invitations.store'), [
                'email' => 'invite@example.com',
                'role' => OrganizationRole::Viewer->value,
            ])
            ->assertForbidden();

        $this->actingIn($organization, $viewer)
            ->post(route('api-tokens.store'), [
                'name' => 'viewer token',
                'abilities' => ['servers:read'],
            ])
            ->assertForbidden();

        $this->actingIn($organization, $viewer)
            ->delete(route('servers.destroy', $server))
            ->assertForbidden();

        $this->assertDatabaseHas('servers', ['id' => $server->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'deleted_at' => null]);
        $this->assertSame(0, AgentCommand::query()->count());
    }

    public function test_developers_can_manage_projects_and_issue_read_only_commands(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $developer = $this->memberWithRole($organization, OrganizationRole::Developer);
        $server = Server::factory()->forOrganization($organization)->ready()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->actingIn($organization, $developer)->get(route('servers.index'))->assertOk();
        $this->actingIn($organization, $developer)->get(route('servers.create'))->assertForbidden();
        $this->actingIn($organization, $developer)->get(route('audit-log.index'))->assertForbidden();
        $this->actingIn($organization, $developer)->get(route('organization.settings'))->assertForbidden();

        $this->actingIn($organization, $developer)
            ->post(route('servers.store'), [
                'name' => 'developer-node',
                'hostname' => 'developer.example.com',
                'roles' => ['application'],
            ])
            ->assertForbidden();

        $this->actingIn($organization, $developer)
            ->post(route('servers.enrollment', $server))
            ->assertForbidden();

        $this->actingIn($organization, $developer)
            ->post(route('servers.state', $server), [
                'status' => ServerStatus::Maintenance->value,
                'confirm' => true,
            ])
            ->assertForbidden();

        $this->actingIn($organization, $developer)
            ->post(route('servers.commands.store', $server), [
                'operation' => AgentOperation::AgentRestart->value,
            ])
            ->assertForbidden();

        $this->actingIn($organization, $developer)
            ->post(route('servers.commands.store', $server), [
                'operation' => AgentOperation::ServerInspect->value,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('agent_commands', [
            'server_id' => $server->id,
            'operation' => AgentOperation::ServerInspect->value,
            'created_by_user_id' => $developer->id,
        ]);

        $this->actingIn($organization, $developer)
            ->post(route('projects.store'), ['name' => 'Developer project'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingIn($organization, $developer)
            ->patch(route('projects.update', $project), ['name' => 'Developer renamed'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingIn($organization, $developer)
            ->delete(route('projects.destroy', $project))
            ->assertForbidden();

        $this->actingIn($organization, $developer)
            ->post(route('members.invitations.store'), [
                'email' => 'invite@example.com',
                'role' => OrganizationRole::Viewer->value,
            ])
            ->assertForbidden();

        $this->actingIn($organization, $developer)
            ->post(route('api-tokens.store'), [
                'name' => 'developer token',
                'abilities' => ['servers:read'],
            ])
            ->assertForbidden();
    }

    public function test_operators_can_control_server_lifecycle_but_not_manage_projects_or_people(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $operator = $this->memberWithRole($organization, OrganizationRole::Operator);
        $server = Server::factory()->forOrganization($organization)->ready()->create();

        $this->actingIn($organization, $operator)
            ->post(route('servers.commands.store', $server), [
                'operation' => AgentOperation::ServerInspect->value,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $command = AgentCommand::query()->where('server_id', $server->id)->sole();

        $this->actingIn($organization, $operator)
            ->post(route('servers.commands.cancel', [$server, $command->command_id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(AgentCommandStatus::Cancelled, $command->refresh()->status);

        $this->actingIn($organization, $operator)
            ->post(route('servers.commands.store', $server), [
                'operation' => AgentOperation::AgentRestart->value,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingIn($organization, $operator)
            ->post(route('servers.state', $server), [
                'status' => ServerStatus::Maintenance->value,
                'confirm' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(ServerStatus::Maintenance, $server->refresh()->status);

        $this->actingIn($organization, $operator)
            ->post(route('servers.state', $server), [
                'status' => ServerStatus::Active->value,
                'confirm' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(ServerStatus::Active, $server->refresh()->status);

        $this->actingIn($organization, $operator)
            ->post(route('servers.store'), [
                'name' => 'operator-node',
                'hostname' => 'operator.example.com',
                'roles' => ['application'],
            ])
            ->assertForbidden();

        $this->actingIn($organization, $operator)
            ->post(route('servers.enrollment', $server))
            ->assertForbidden();

        $this->actingIn($organization, $operator)
            ->post(route('servers.certificates.revoke', $server), ['reason' => 'compromised'])
            ->assertForbidden();

        $this->actingIn($organization, $operator)
            ->post(route('projects.store'), ['name' => 'Operator project'])
            ->assertForbidden();

        $this->actingIn($organization, $operator)
            ->post(route('members.invitations.store'), [
                'email' => 'invite@example.com',
                'role' => OrganizationRole::Viewer->value,
            ])
            ->assertForbidden();

        $this->actingIn($organization, $operator)->get(route('audit-log.index'))->assertForbidden();
        $this->actingIn($organization, $operator)->get(route('organization.settings'))->assertForbidden();
    }

    public function test_admins_can_manage_infrastructure_people_and_tokens_but_not_organization_settings(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $admin = $this->memberWithRole($organization, OrganizationRole::Admin);
        $server = Server::factory()->forOrganization($organization)->ready()->create();
        $anotherServer = Server::factory()->forOrganization($organization)->ready()->create();

        $this->actingIn($organization, $admin)->get(route('servers.create'))->assertOk();
        $this->actingIn($organization, $admin)->get(route('audit-log.index'))->assertOk();
        $this->actingIn($organization, $admin)->get(route('organization.settings'))->assertForbidden();

        $this->actingIn($organization, $admin)
            ->post(route('servers.store'), [
                'name' => 'admin-node',
                'hostname' => 'admin.example.com',
                'roles' => ['application'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingIn($organization, $admin)
            ->post(route('servers.enrollment', $server))
            ->assertRedirect()
            ->assertSessionHas('enrollment_token');

        $this->actingIn($organization, $admin)
            ->post(route('servers.certificates.revoke', $server), ['reason' => 'operator request'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingIn($organization, $admin)
            ->post(route('servers.state', $anotherServer), [
                'status' => ServerStatus::Draining->value,
                'confirm' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(ServerStatus::Draining, $anotherServer->refresh()->status);

        $this->actingIn($organization, $admin)
            ->post(route('projects.store'), ['name' => 'Admin project'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingIn($organization, $admin)
            ->post(route('members.invitations.store'), [
                'email' => 'admin-invite@example.com',
                'role' => OrganizationRole::Developer->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('invitation_url');

        $this->actingIn($organization, $admin)
            ->post(route('api-tokens.store'), [
                'name' => 'admin token',
                'abilities' => ['servers:read', 'projects:read'],
            ])
            ->assertRedirect()
            ->assertSessionHas('plaintext_token');

        $this->actingIn($organization, $admin)
            ->delete(route('servers.destroy', $server))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('servers', ['id' => $server->id]);
    }

    public function test_only_owners_can_update_organization_settings(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $admin = $this->memberWithRole($organization, OrganizationRole::Admin);

        $this->actingIn($organization, $owner)
            ->get(route('organization.settings'))
            ->assertOk();

        $this->actingIn($organization, $owner)
            ->patch(route('organization.update'), ['name' => 'Renamed organization'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed organization', $organization->refresh()->name);

        $this->actingIn($organization, $admin)
            ->patch(route('organization.update'), ['name' => 'Admin rename'])
            ->assertForbidden();
    }

    public function test_admins_can_issue_and_cancel_commands_they_are_allowed_to_operate(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $admin = $this->memberWithRole($organization, OrganizationRole::Admin);
        $server = Server::factory()->forOrganization($organization)->ready()->create();

        $this->actingIn($organization, $admin)
            ->post(route('servers.commands.store', $server), [
                'operation' => AgentOperation::AgentRestart->value,
                'reason' => 'Applying kernel update',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $command = AgentCommand::query()->where('server_id', $server->id)->sole();

        $this->assertSame(['reason' => 'Applying kernel update'], $command->payload);

        $this->actingIn($organization, $admin)
            ->post(route('servers.commands.cancel', [$server, $command->command_id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(AgentCommandStatus::Cancelled, $command->refresh()->status);
    }

    public function test_role_rank_helpers_match_the_documented_permissions(): void
    {
        $this->assertTrue(OrganizationRole::Owner->canManageOrganization());
        $this->assertFalse(OrganizationRole::Admin->canManageOrganization());

        foreach ([OrganizationRole::Owner, OrganizationRole::Admin] as $role) {
            $this->assertTrue($role->canManageInfrastructure());
            $this->assertTrue($role->canManageMembers());
            $this->assertTrue($role->canOperate());
            $this->assertTrue($role->canDeploy());
        }

        $this->assertFalse(OrganizationRole::Developer->canManageInfrastructure());
        $this->assertFalse(OrganizationRole::Developer->canOperate());
        $this->assertTrue(OrganizationRole::Developer->canDeploy());

        $this->assertFalse(OrganizationRole::Operator->canManageInfrastructure());
        $this->assertTrue(OrganizationRole::Operator->canOperate());
        $this->assertFalse(OrganizationRole::Operator->canDeploy());

        $this->assertFalse(OrganizationRole::Viewer->canManageInfrastructure());
        $this->assertFalse(OrganizationRole::Viewer->canOperate());
        $this->assertFalse(OrganizationRole::Viewer->canDeploy());
        $this->assertTrue(OrganizationRole::Viewer->canView());
    }
}
