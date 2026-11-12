<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Operations\Enums\AgentCommandStatus;
use App\Domains\Operations\Enums\AgentOperation;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Models\AgentCommand;
use App\Domains\Operations\Services\AgentCommandService;
use App\Domains\Organizations\Enums\OrganizationRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Models\Project;
use App\Domains\Resources\Actions\CreateResource;
use App\Domains\Resources\Actions\DeleteResource;
use App\Domains\Resources\Actions\HandleResourceCommandResult;
use App\Domains\Resources\Actions\StartResource;
use App\Domains\Resources\Actions\StopResource;
use App\Domains\Resources\Enums\ResourceStatus;
use App\Domains\Resources\Enums\ResourceType;
use App\Domains\Resources\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithAgents;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class ResourceProvisioningTest extends TestCase
{
    use InteractsWithAgents, InteractsWithOrganizations, RefreshDatabase;

    public function test_creating_a_resource_queues_a_typed_agent_command(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $resource = app(CreateResource::class)->execute(
            $project,
            $project->environments()->firstOrFail(),
            $server,
            'main-db',
            ResourceType::Postgresql,
            $owner,
        );

        $this->assertSame(ResourceStatus::Provisioning, $resource->status);
        $this->assertSame('postgres:16-alpine', $resource->image);
        $this->assertStringStartsWith('cloudforge-', $resource->container_name);
        $this->assertNull($resource->password);
        $this->assertNotNull($resource->last_command_id);

        $command = AgentCommand::query()->where('command_id', $resource->last_command_id)->sole();

        $this->assertSame(AgentOperation::ResourceCreate, $command->operation);
        $this->assertSame($resource->id, $command->payload['resource_id']);
        $this->assertSame('postgresql', $command->payload['resource_type']);
        $this->assertSame($resource->container_name, $command->payload['container_name']);
        $this->assertSame(AgentCommandStatus::Pending, $command->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::AgentCommandIssued->value,
            'organization_id' => $organization->id,
        ]);
    }

    public function test_agent_result_activates_the_resource_and_redacts_the_password(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $resource = app(CreateResource::class)->execute(
            $project,
            $project->environments()->firstOrFail(),
            $server,
            'main-db',
            ResourceType::Postgresql,
            $owner,
        );

        $command = AgentCommand::query()->where('command_id', $resource->last_command_id)->sole();
        $result = [
            'resource_id' => $resource->id,
            'status' => 'running',
            'container_id' => 'container-123',
            'host' => '127.0.0.1',
            'port' => 32768,
            'username' => 'cloudforge',
            'database' => 'cloudforge',
            'password' => 'generated-secret',
        ];

        app(AgentCommandService::class)->complete($command, $server, true, $result);
        app(HandleResourceCommandResult::class)->handle($command->refresh(), true, $result, null);

        $resource->refresh();

        $this->assertSame(ResourceStatus::Active, $resource->status);
        $this->assertSame('generated-secret', $resource->password);
        $this->assertSame(32768, $resource->port);
        $this->assertSame('container-123', $resource->container_id);
        $this->assertNull($resource->credentials_revealed_at);
        $this->assertArrayNotHasKey('password', $command->refresh()->result);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ResourceProvisioned->value,
            'organization_id' => $organization->id,
        ]);
    }

    public function test_credentials_can_be_viewed_repeatedly_by_authorized_users(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $resource = Resource::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->active()
            ->create(['name' => 'main-db', 'password' => 'reveal-secret']);

        $response = $this->actingIn($organization, $owner)
            ->post(route('resources.reveal', [$project, $resource]));

        $response->assertRedirect();
        $this->assertSame('reveal-secret', session('resource_credentials.password'));
        $this->assertNotNull($resource->refresh()->credentials_revealed_at);

        $second = $this->actingIn($organization, $owner)
            ->post(route('resources.reveal', [$project, $resource]));

        $second->assertRedirect();
        $this->assertSame('reveal-secret', session('resource_credentials.password'));
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_start_stop_and_delete_are_agent_driven(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $resource = Resource::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->active()
            ->create(['name' => 'main-db', 'status' => ResourceStatus::Stopped]);

        app(StartResource::class)->execute($resource, $owner);
        $this->assertSame(ResourceStatus::Starting, $resource->refresh()->status);

        $startCommand = AgentCommand::query()->where('command_id', $resource->last_command_id)->sole();
        app(AgentCommandService::class)->complete($startCommand, $server, true, ['status' => 'running']);
        app(HandleResourceCommandResult::class)->handle($startCommand->refresh(), true, ['status' => 'running'], null);
        $this->assertSame(ResourceStatus::Active, $resource->refresh()->status);

        app(StopResource::class)->execute($resource, $owner);
        $this->assertSame(ResourceStatus::Stopping, $resource->refresh()->status);

        $stopCommand = AgentCommand::query()->where('command_id', $resource->last_command_id)->sole();
        app(AgentCommandService::class)->complete($stopCommand, $server, true, ['status' => 'stopped']);
        app(HandleResourceCommandResult::class)->handle($stopCommand->refresh(), true, ['status' => 'stopped'], null);
        $this->assertSame(ResourceStatus::Stopped, $resource->refresh()->status);

        app(DeleteResource::class)->execute($resource, $owner);
        $this->assertSame(ResourceStatus::Deleting, $resource->refresh()->status);

        $deleteCommand = AgentCommand::query()->where('command_id', $resource->last_command_id)->sole();
        app(AgentCommandService::class)->complete($deleteCommand, $server, true, ['status' => 'deleted']);
        app(HandleResourceCommandResult::class)->handle($deleteCommand->refresh(), true, ['status' => 'deleted'], null);

        $this->assertSoftDeleted('resources', ['id' => $resource->id]);
        $this->assertDatabaseHas('resources', [
            'id' => $resource->id,
            'status' => ResourceStatus::Deleted->value,
            'password' => null,
            'deleted_at' => now(),
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ResourceDeleted->value,
            'organization_id' => $organization->id,
        ]);
    }

    public function test_a_credential_less_resource_cannot_be_started(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $resource = Resource::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->create([
                'name' => 'no-credentials',
                'status' => ResourceStatus::Failed,
                'password' => null,
            ]);

        $this->actingIn($organization, $owner)
            ->post(route('resources.start', [$project, $resource]))
            ->assertSessionHasErrors('resource');

        $this->assertSame(ResourceStatus::Failed, $resource->refresh()->status);
    }

    public function test_a_credential_less_failed_resource_can_be_recovered(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $resource = Resource::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->create([
                'name' => 'lost-credentials',
                'status' => ResourceStatus::Failed,
                'password' => null,
            ]);

        $response = $this->actingIn($organization, $owner)
            ->post(route('resources.recover', [$project, $resource]));

        $response->assertRedirect();
        $resource->refresh();

        $this->assertSame(ResourceStatus::Provisioning, $resource->status);
        $this->assertNotNull($resource->last_command_id);

        $command = AgentCommand::query()
            ->where('command_id', $resource->last_command_id)
            ->sole();
        $this->assertSame(AgentOperation::ResourceCreate, $command->operation);
        $this->assertSame($resource->id, $command->payload['resource_id']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ResourceRecoveryRequested->value,
            'organization_id' => $organization->id,
        ]);
    }

    public function test_recovery_is_rejected_when_credentials_already_exist(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $resource = Resource::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->active()
            ->create(['name' => 'healthy-db']);

        $this->actingIn($organization, $owner)
            ->post(route('resources.recover', [$project, $resource]))
            ->assertSessionHasErrors('resource');

        $this->assertSame(ResourceStatus::Active, $resource->refresh()->status);
    }

    public function test_viewers_cannot_recover_credentials(): void
    {
        [$owner, $organization, $project, $server] = $this->context();
        $viewer = $this->memberWithRole($organization, OrganizationRole::Viewer);

        $resource = Resource::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->create([
                'name' => 'viewer-visible-db',
                'status' => ResourceStatus::Failed,
            ]);

        $this->actingIn($organization, $viewer)
            ->post(route('resources.recover', [$project, $resource]))
            ->assertForbidden();

        $this->assertSame(ResourceStatus::Failed, $resource->refresh()->status);
    }

    public function test_refresh_issues_an_inspect_command_without_a_server_error(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $resource = Resource::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->active()
            ->create(['name' => 'refresh-db']);

        $response = $this->actingIn($organization, $owner)
            ->post(route('resources.refresh', [$project, $resource]));

        $response->assertRedirect();
        $resource->refresh();

        $this->assertSame(ResourceStatus::Refreshing, $resource->status);

        $command = AgentCommand::query()
            ->where('command_id', $resource->last_command_id)
            ->sole();
        $this->assertSame(AgentOperation::ResourceInspect, $command->operation);

        $result = ['status' => 'running', 'port' => 32768];
        app(AgentCommandService::class)->complete($command, $server, true, $result);
        app(HandleResourceCommandResult::class)->handle($command->refresh(), true, $result, null);

        $this->assertSame(ResourceStatus::Active, $resource->refresh()->status);
    }

    public function test_agent_result_endpoint_activates_the_resource_and_scrubs_the_password(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $resource = app(CreateResource::class)->execute(
            $project,
            $project->environments()->firstOrFail(),
            $server,
            'main-db',
            ResourceType::Postgresql,
            $owner,
        );

        $command = AgentCommand::query()->where('command_id', $resource->last_command_id)->sole();
        $issued = $this->issueServerCertificate($server);

        $result = [
            'resource_id' => $resource->id,
            'status' => 'running',
            'container_id' => 'container-123',
            'host' => '127.0.0.1',
            'port' => 32768,
            'username' => 'cloudforge',
            'database' => 'cloudforge',
            'password' => 'endpoint-secret',
        ];

        $this->signedAgentRequest(
            $issued['certificate'],
            $issued['key'],
            'POST',
            route('agent.commands.result', ['command' => $command->command_id]),
            ['status' => 'completed', 'result' => $result],
        )->assertOk();

        $this->assertSame(ResourceStatus::Active, $resource->refresh()->status);
        $this->assertSame('endpoint-secret', $resource->password);
        $this->assertArrayNotHasKey('password', $command->refresh()->result);
    }

    public function test_owner_can_provision_a_resource_from_the_project_page(): void
    {
        [$owner, $organization, $project, $server] = $this->context();
        $environment = $project->environments()->firstOrFail();

        $this->actingIn($organization, $owner)
            ->post(route('resources.store', $project), [
                'name' => 'main-db',
                'type' => 'redis',
                'environment_id' => $environment->id,
                'server_id' => $server->id,
            ])
            ->assertRedirect();

        $resource = Resource::query()->sole();

        $this->assertSame('main-db', $resource->name);
        $this->assertSame(ResourceType::Redis, $resource->type);
        $this->assertSame(ResourceStatus::Provisioning, $resource->status);
        $this->assertDatabaseHas('agent_commands', [
            'server_id' => $server->id,
            'operation' => AgentOperation::ResourceCreate->value,
        ]);
    }

    public function test_databases_page_lists_real_resources_without_credentials(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $resource = Resource::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->active()
            ->create(['name' => 'main-db', 'password' => 'hidden-secret']);

        $this->actingIn($organization, $owner)
            ->get(route('databases.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Databases/Index')
                ->has('resources', 1)
                ->where('resources.0.id', $resource->id)
                ->where('resources.0.project.name', $project->name)
                ->where('resources.0.status', 'active')
                ->where('canProvision', true)
                ->missing('resources.0.password'));
    }

    public function test_databases_page_is_scoped_to_the_current_organization(): void
    {
        [$owner, $organization, $project, $server] = $this->context();
        [$otherOwner, $otherOrganization, $otherProject, $otherServer] = $this->context();

        Resource::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->active()
            ->create(['name' => 'visible-db']);

        Resource::factory()
            ->forProject($otherProject, $otherProject->environments()->firstOrFail(), $otherServer)
            ->active()
            ->create(['name' => 'hidden-db']);

        $this->actingIn($organization, $owner)
            ->get(route('databases.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('resources', 1)
                ->where('resources.0.name', 'visible-db'));
    }

    public function test_dashboard_reports_real_database_counts_and_attention(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        Resource::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->active()
            ->create(['name' => 'healthy-db']);

        Resource::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->create(['name' => 'failed-db', 'status' => ResourceStatus::Failed, 'last_error' => 'pull failed']);

        $this->actingIn($organization, $owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('stats.databases', 2)
                ->where('resourceStats.active', 1)
                ->where('resourceStats.failed', 1)
                ->has('resources', 2)
                ->has('attention', 1)
                ->where('attention.0.title', 'failed-db failed'));
    }

    public function test_project_tabs_expose_real_resources_activity_and_settings(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $environment = $project->environments()->firstOrFail();
        $resource = app(CreateResource::class)->execute(
            $project,
            $environment,
            $server,
            'main-db',
            ResourceType::Postgresql,
            $owner,
        );

        $this->actingIn($organization, $owner)
            ->get(route('projects.databases', $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Show')
                ->where('tab', 'databases')
                ->has('resources', 1)
                ->where('resources.0.id', $resource->id)
                ->where('project.environment_count', 1)
                ->where('stats.databases', 1));

        $this->actingIn($organization, $owner)
            ->get(route('projects.environments', $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Show')
                ->where('tab', 'environments')
                ->where('project.environments.0.resource_count', 1));

        $this->actingIn($organization, $owner)
            ->get(route('projects.activity', $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Show')
                ->where('tab', 'activity')
                ->has('activity'));

        $this->actingIn($organization, $owner)
            ->get(route('projects.settings', $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Show')
                ->where('tab', 'settings')
                ->where('canManage', true));
    }

    public function test_projects_page_reports_real_summary_counts(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        Resource::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->active()
            ->create(['name' => 'main-db']);

        $this->actingIn($organization, $owner)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Index')
                ->where('summary.projects', 1)
                ->where('summary.databases', 1)
                ->where('summary.environments', 1)
                ->where('projects.0.resource_count', 1)
                ->where('projects.0.environment_count', 1));
    }

    public function test_a_provisioning_resource_can_be_deleted_to_recover_from_a_stuck_command(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $resource = app(CreateResource::class)->execute(
            $project,
            $project->environments()->firstOrFail(),
            $server,
            'stuck-db',
            ResourceType::Postgresql,
            $owner,
        );

        $this->assertSame(ResourceStatus::Provisioning, $resource->status);
        $this->assertTrue($resource->status->canDelete());

        app(DeleteResource::class)->execute($resource, $owner);

        $this->assertSame(ResourceStatus::Deleting, $resource->refresh()->status);
        $this->assertDatabaseHas('agent_commands', [
            'server_id' => $server->id,
            'operation' => AgentOperation::ResourceDelete->value,
        ]);
    }

    public function test_viewers_cannot_provision_resources(): void
    {
        [$owner, $organization, $project, $server] = $this->context();
        $viewer = $this->memberWithRole($organization, OrganizationRole::Viewer);

        $this->actingIn($organization, $viewer)
            ->post(route('resources.store', $project), [
                'name' => 'blocked',
                'type' => 'postgresql',
                'environment_id' => $project->environments()->firstOrFail()->id,
                'server_id' => $server->id,
            ])
            ->assertForbidden();

        $this->assertSame(0, Resource::query()->count());
    }

    public function test_cross_organization_resource_routes_return_not_found(): void
    {
        [$owner, $organization, $project] = $this->context();
        [$otherOwner, $otherOrganization, $otherProject, $otherServer] = $this->context();

        $otherResource = Resource::factory()
            ->forProject($otherProject, $otherProject->environments()->firstOrFail(), $otherServer)
            ->active()
            ->create();

        $this->actingIn($organization, $owner)
            ->post(route('resources.start', [$project, $otherResource]))
            ->assertNotFound();
    }

    public function test_reconcile_fails_a_resource_whose_command_expired(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $resource = app(CreateResource::class)->execute(
            $project,
            $project->environments()->firstOrFail(),
            $server,
            'expired-db',
            ResourceType::Postgresql,
            $owner,
        );

        AgentCommand::query()
            ->where('command_id', $resource->last_command_id)
            ->update(['expires_at' => now()->subMinute()]);

        Artisan::call('resources:reconcile');

        $this->assertSame(ResourceStatus::Failed, $resource->refresh()->status);
        $this->assertNotNull($resource->last_error);
    }

    public function test_reconcile_fails_a_transitional_resource_without_a_command(): void
    {
        [, , $project, $server] = $this->context();

        $resource = Resource::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->create(['name' => 'orphaned-db']);

        Artisan::call('resources:reconcile');

        $resource->refresh();

        $this->assertSame(ResourceStatus::Failed, $resource->status);
        $this->assertSame('The resource has no provisioning command to complete.', $resource->last_error);
    }

    public function test_reconcile_expires_the_agent_command_with_the_resource(): void
    {
        [$owner, , $project, $server] = $this->context();

        $resource = app(CreateResource::class)->execute(
            $project,
            $project->environments()->firstOrFail(),
            $server,
            'expired-command-db',
            ResourceType::Postgresql,
            $owner,
        );

        $command = AgentCommand::query()
            ->where('command_id', $resource->last_command_id)
            ->sole();
        $command->forceFill(['expires_at' => now()->subMinute()])->save();

        Artisan::call('resources:reconcile');

        $command->refresh();
        $this->assertSame(AgentCommandStatus::Expired, $command->status);
        $this->assertNotNull($command->completed_at);
        $this->assertNotNull($command->error_message);
        $this->assertSame(ResourceStatus::Failed, $resource->refresh()->status);
    }

    public function test_project_page_lists_resources_without_exposing_credentials(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $resource = Resource::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->active()
            ->create(['name' => 'main-db', 'password' => 'hidden-secret']);

        $this->actingIn($organization, $owner)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Show')
                ->has('resources', 1)
                ->where('resources.0.id', $resource->id)
                ->where('resources.0.name', 'main-db')
                ->where('resources.0.status', 'active')
                ->missing('resources.0.password')
                ->where('servers.0.id', $server->id));
    }

    /**
     * @return array{0: User, 1: Organization, 2: Project, 3: Server}
     */
    private function context(): array
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $server = Server::factory()->forOrganization($organization)->ready()->create();

        return [$owner, $organization, $project, $server];
    }
}
