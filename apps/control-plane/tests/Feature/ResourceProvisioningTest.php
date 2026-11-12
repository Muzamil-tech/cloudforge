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

    public function test_credentials_are_revealed_once_from_the_project_route(): void
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
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ResourceCredentialsRevealed->value,
            'organization_id' => $organization->id,
        ]);

        $second = $this->actingIn($organization, $owner)
            ->post(route('resources.reveal', [$project, $resource]));

        $second->assertRedirect();
        $this->assertNull(session('resource_credentials'));
        $this->assertSame('These credentials have already been revealed or are not ready yet.', session('error'));
    }

    public function test_start_stop_and_delete_are_agent_driven(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $resource = Resource::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
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
