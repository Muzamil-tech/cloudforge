<?php

namespace Tests\Feature;

use App\Domains\Applications\Actions\CreateApplication;
use App\Domains\Applications\Actions\DeleteApplication;
use App\Domains\Applications\Actions\DeployApplication;
use App\Domains\Applications\Actions\HandleApplicationCommandResult;
use App\Domains\Applications\Actions\RefreshApplication;
use App\Domains\Applications\Actions\RollbackApplication;
use App\Domains\Applications\Actions\StartApplication;
use App\Domains\Applications\Actions\StopApplication;
use App\Domains\Applications\Enums\ApplicationStatus;
use App\Domains\Applications\Enums\DeploymentStatus;
use App\Domains\Applications\Models\Application;
use App\Domains\Applications\Models\Deployment;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithAgents;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class ApplicationDeploymentTest extends TestCase
{
    use InteractsWithAgents, InteractsWithOrganizations, RefreshDatabase;

    public function test_creating_an_application_queues_a_typed_deploy_command(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $application = app(CreateApplication::class)->execute(
            $project,
            $project->environments()->firstOrFail(),
            $server,
            'web',
            'nginx:1.27-alpine',
            80,
            '/',
            $owner,
        );

        $this->assertSame(ApplicationStatus::Deploying, $application->status);
        $this->assertSame('nginx:1.27-alpine', $application->image);
        $this->assertSame(80, $application->container_port);
        $this->assertNotNull($application->last_command_id);

        $deployment = Deployment::query()
            ->where('application_id', $application->id)
            ->sole();

        $this->assertSame(DeploymentStatus::Deploying, $deployment->status);
        $this->assertSame('nginx:1.27-alpine', $deployment->image);
        $this->assertNull($deployment->metadata['previous_container_name']);

        $command = AgentCommand::query()
            ->where('command_id', $application->last_command_id)
            ->sole();

        $this->assertSame(AgentOperation::ApplicationDeploy, $command->operation);
        $this->assertSame($application->id, $command->payload['application_id']);
        $this->assertSame($deployment->id, $command->payload['deployment_id']);
        $this->assertSame($deployment->container_name, $command->payload['container_name']);
        $this->assertNull($command->payload['previous_container_name']);
        $this->assertSame('nginx:1.27-alpine', $command->payload['image']);
        $this->assertSame(80, $command->payload['container_port']);
        $this->assertSame('/', $command->payload['health_path']);
        $this->assertSame(AgentCommandStatus::Pending, $command->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ApplicationCreated->value,
            'organization_id' => $organization->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::DeploymentStarted->value,
            'organization_id' => $organization->id,
        ]);
    }

    public function test_a_successful_deployment_activates_the_application_and_supersedes_the_previous_deployment(): void
    {
        [$owner, , $project, $server] = $this->context();

        $application = $this->deployableApplication($project, $server, $owner);
        $firstDeployment = $this->completeDeployment(
            $application,
            $server,
            $application->latestDeployment,
            'container-one',
            32780,
            "first deployment\n",
        );

        $this->assertSame(ApplicationStatus::Active, $application->refresh()->status);
        $this->assertSame(DeploymentStatus::Active, $firstDeployment->refresh()->status);

        $application->forceFill(['image' => 'nginx:1.28-alpine'])->save();

        $secondDeployment = app(DeployApplication::class)->execute($application, $owner);

        $this->assertSame(DeploymentStatus::Deploying, $secondDeployment->status);
        $this->assertSame('nginx:1.28-alpine', $secondDeployment->image);
        $this->assertSame($application->container_name, $secondDeployment->metadata['previous_container_name']);
        $this->assertSame(ApplicationStatus::Active->value, $secondDeployment->metadata['previous_status']);

        $command = AgentCommand::query()
            ->where('command_id', $application->refresh()->last_command_id)
            ->sole();
        $this->assertSame($secondDeployment->id, $command->payload['deployment_id']);
        $this->assertSame($firstDeployment->container_name, $command->payload['previous_container_name']);

        $this->completeDeployment(
            $application,
            $server,
            $secondDeployment,
            'container-two',
            32781,
            "second deployment\n",
        );

        $application->refresh();

        $this->assertSame(ApplicationStatus::Active, $application->status);
        $this->assertSame('nginx:1.28-alpine', $application->image);
        $this->assertSame('container-two', $application->container_id);
        $this->assertSame(32781, $application->host_port);
        $this->assertSame(DeploymentStatus::Superseded, $firstDeployment->refresh()->status);
        $this->assertSame(DeploymentStatus::Active, $secondDeployment->refresh()->status);
        $this->assertSame("second deployment\n", $secondDeployment->logs);
        $this->assertDatabaseHas('deployment_events', [
            'deployment_id' => $secondDeployment->id,
            'level' => 'info',
            'message' => 'Deployment completed and the application is healthy.',
        ]);
    }

    public function test_a_failed_deployment_restores_the_previous_active_application(): void
    {
        [$owner, , $project, $server] = $this->context();

        $application = $this->deployableApplication($project, $server, $owner);
        $this->completeDeployment(
            $application,
            $server,
            $application->latestDeployment,
            'container-one',
            32780,
            "first deployment\n",
        );

        $application->refresh();
        $previousContainer = $application->container_name;
        $previousPort = $application->host_port;

        $application->forceFill(['image' => 'nginx:1.28-alpine'])->save();
        $deployment = app(DeployApplication::class)->execute($application, $owner);

        $this->completeDeploymentFailure($application, $server, $deployment, "listen tcp: address already in use\n");

        $application->refresh();

        $this->assertSame(ApplicationStatus::Active, $application->status);
        $this->assertSame($previousContainer, $application->container_name);
        $this->assertSame($previousPort, $application->host_port);
        $this->assertNotNull($application->last_error);
        $this->assertStringContainsString('did not become healthy', $application->last_error);
        $this->assertSame(DeploymentStatus::Failed, $deployment->refresh()->status);
        $this->assertStringContainsString('address already in use', (string) $deployment->logs);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::DeploymentFailed->value,
        ]);
    }

    public function test_rollback_creates_a_new_deployment_from_the_previous_image(): void
    {
        [$owner, , $project, $server] = $this->context();

        $application = $this->deployableApplication($project, $server, $owner);
        $first = $this->completeDeployment(
            $application,
            $server,
            $application->latestDeployment,
            'container-one',
            32780,
            "first\n",
        );

        $application->forceFill(['image' => 'nginx:1.28-alpine'])->save();
        $second = $this->completeDeployment(
            $application,
            $server,
            app(DeployApplication::class)->execute($application, $owner),
            'container-two',
            32781,
            "second\n",
        );

        $rollback = app(RollbackApplication::class)->execute($application->refresh(), $first->refresh(), $owner);

        $this->assertSame('nginx:1.27-alpine', $rollback->image);
        $this->assertSame($first->id, $rollback->metadata['rollback_of_deployment_id']);
        $this->assertSame(DeploymentStatus::Deploying, $rollback->status);
        $this->assertSame(ApplicationStatus::Deploying, $application->refresh()->status);

        $command = AgentCommand::query()
            ->where('command_id', $application->last_command_id)
            ->sole();
        $this->assertSame('nginx:1.27-alpine', $command->payload['image']);
        $this->assertSame($second->container_name, $command->payload['previous_container_name']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::DeploymentRolledBack->value,
        ]);
    }

    public function test_application_lifecycle_actions_are_agent_driven(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $application = $this->deployableApplication($project, $server, $owner);
        $this->completeDeployment(
            $application,
            $server,
            $application->latestDeployment,
            'container-one',
            32780,
            "started\n",
        );

        $application->refresh();

        app(StopApplication::class)->execute($application, $owner);
        $this->assertSame(ApplicationStatus::Stopping, $application->refresh()->status);
        $stopCommand = AgentCommand::query()->where('command_id', $application->last_command_id)->sole();
        $this->assertSame(AgentOperation::ApplicationStop, $stopCommand->operation);
        app(AgentCommandService::class)->complete($stopCommand, $server, true, ['status' => 'stopped']);
        app(HandleApplicationCommandResult::class)->handle($stopCommand->refresh(), true, ['status' => 'stopped'], null);
        $this->assertSame(ApplicationStatus::Stopped, $application->refresh()->status);

        app(StartApplication::class)->execute($application, $owner);
        $this->assertSame(ApplicationStatus::Starting, $application->refresh()->status);
        $startCommand = AgentCommand::query()->where('command_id', $application->last_command_id)->sole();
        $this->assertSame(AgentOperation::ApplicationStart, $startCommand->operation);
        app(AgentCommandService::class)->complete($startCommand, $server, true, ['status' => 'running']);
        app(HandleApplicationCommandResult::class)->handle($startCommand->refresh(), true, ['status' => 'running'], null);
        $this->assertSame(ApplicationStatus::Active, $application->refresh()->status);

        app(RefreshApplication::class)->execute($application, $owner);
        $this->assertSame(ApplicationStatus::Refreshing, $application->refresh()->status);
        $inspectCommand = AgentCommand::query()->where('command_id', $application->last_command_id)->sole();
        $this->assertSame(AgentOperation::ApplicationInspect, $inspectCommand->operation);
        $this->assertSame(80, $inspectCommand->payload['container_port']);
        $inspectResult = ['status' => 'running', 'container_id' => 'container-one', 'port' => 32780, 'health' => 'healthy', 'image' => 'nginx:1.27-alpine'];
        app(AgentCommandService::class)->complete($inspectCommand, $server, true, $inspectResult);
        app(HandleApplicationCommandResult::class)->handle($inspectCommand->refresh(), true, $inspectResult, null);
        $this->assertSame(ApplicationStatus::Active, $application->refresh()->status);

        app(DeleteApplication::class)->execute($application, $owner);
        $this->assertSame(ApplicationStatus::Deleting, $application->refresh()->status);
        $deleteCommand = AgentCommand::query()->where('command_id', $application->last_command_id)->sole();
        $this->assertSame(AgentOperation::ApplicationDelete, $deleteCommand->operation);
        app(AgentCommandService::class)->complete($deleteCommand, $server, true, ['status' => 'deleted']);
        app(HandleApplicationCommandResult::class)->handle($deleteCommand->refresh(), true, ['status' => 'deleted'], null);

        $this->assertSoftDeleted('applications', ['id' => $application->id]);
        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => ApplicationStatus::Deleted->value,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ApplicationDeleted->value,
            'organization_id' => $organization->id,
        ]);
    }

    public function test_the_project_page_exposes_application_counts_and_the_applications_tab(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        Application::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->active()
            ->create(['name' => 'web-app']);

        $this->actingIn($organization, $owner)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Show')
                ->has('applications', 1)
                ->where('applications.0.name', 'web-app')
                ->where('stats.applications', 1)
                ->where('stats.active_applications', 1)
                ->where('project.application_count', 1)
                ->where('canCreateApplication', true));
    }

    public function test_the_applications_page_lists_real_applications(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $application = Application::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->active()
            ->create(['name' => 'web-app', 'image' => 'nginx:1.27-alpine']);

        $this->actingIn($organization, $owner)
            ->get(route('applications.index', $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Applications/Index')
                ->has('applications', 1)
                ->where('applications.0.id', $application->id)
                ->where('applications.0.image', 'nginx:1.27-alpine')
                ->where('canCreate', true)
                ->has('servers', 1));
    }

    public function test_the_application_page_lists_deployments_and_real_logs(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $application = $this->deployableApplication($project, $server, $owner);
        $this->completeDeployment(
            $application,
            $server,
            $application->latestDeployment,
            'container-one',
            32780,
            "server started\nlistening on :80\n",
        );

        $this->actingIn($organization, $owner)
            ->get(route('applications.show', [$project, $application]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Applications/Show')
                ->where('application.id', $application->id)
                ->where('application.status', 'active')
                ->where('application.container_id', 'container-one')
                ->where('application.host_port', 32780)
                ->has('application.deployments', 1)
                ->where('application.deployments.0.status', 'active')
                ->where('application.deployments.0.logs', "server started\nlistening on :80\n")
                ->missing('application.password'));
    }

    public function test_the_store_route_creates_an_application_and_its_first_deployment(): void
    {
        [$owner, $organization, $project, $server] = $this->context();
        $environment = $project->environments()->firstOrFail();

        $response = $this->actingIn($organization, $owner)->post(route('applications.store', $project), [
            'name' => 'web',
            'image' => 'nginx:1.27-alpine',
            'container_port' => 80,
            'health_path' => '/',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
        ]);

        $application = Application::query()->where('name', 'web')->sole();

        $response->assertRedirect(route('applications.show', [$project, $application]));
        $this->assertSame(ApplicationStatus::Deploying, $application->status);
        $this->assertDatabaseHas('deployments', [
            'application_id' => $application->id,
            'status' => DeploymentStatus::Deploying->value,
            'image' => 'nginx:1.27-alpine',
        ]);
        $this->assertDatabaseHas('agent_commands', [
            'server_id' => $server->id,
            'operation' => AgentOperation::ApplicationDeploy->value,
        ]);
    }

    public function test_the_rollback_route_creates_a_new_deployment(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $application = $this->deployableApplication($project, $server, $owner);
        $first = $this->completeDeployment(
            $application,
            $server,
            $application->latestDeployment,
            'container-one',
            32780,
            "first\n",
        );

        $application->forceFill(['image' => 'nginx:1.28-alpine'])->save();
        $this->completeDeployment(
            $application,
            $server,
            app(DeployApplication::class)->execute($application, $owner),
            'container-two',
            32781,
            "second\n",
        );

        $this->actingIn($organization, $owner)
            ->post(route('applications.rollback', [$project, $application, $first->refresh()]))
            ->assertRedirect();

        $this->assertDatabaseHas('deployments', [
            'application_id' => $application->id,
            'image' => 'nginx:1.27-alpine',
            'status' => DeploymentStatus::Deploying->value,
        ]);
    }

    public function test_the_destroy_route_queues_a_delete_command_and_redirects_to_the_list(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $application = Application::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->active()
            ->create(['name' => 'delete-me']);

        $this->actingIn($organization, $owner)
            ->delete(route('applications.destroy', [$project, $application]))
            ->assertRedirect(route('applications.index', $project));

        $this->assertSame(ApplicationStatus::Deleting, $application->refresh()->status);
        $this->assertDatabaseHas('agent_commands', [
            'server_id' => $server->id,
            'operation' => AgentOperation::ApplicationDelete->value,
        ]);
    }

    public function test_invalid_application_images_are_rejected(): void
    {
        [$owner, $organization, $project, $server] = $this->context();

        $this->actingIn($organization, $owner)->post(route('applications.store', $project), [
            'name' => 'bad-image',
            'image' => 'https://evil.example.com/container',
            'container_port' => 80,
            'health_path' => '/',
            'environment_id' => $project->environments()->firstOrFail()->id,
            'server_id' => $server->id,
        ])->assertSessionHasErrors('image');

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_viewers_cannot_deploy_applications(): void
    {
        [, $organization, $project, $server] = $this->context();
        $viewer = $this->memberWithRole($organization, OrganizationRole::Viewer);

        $application = Application::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->active()
            ->create(['name' => 'viewer-app']);

        $this->actingIn($organization, $viewer)
            ->post(route('applications.deploy', [$project, $application]))
            ->assertForbidden();
    }

    public function test_cross_organization_application_routes_return_not_found(): void
    {
        [$owner, , $project, $server] = $this->context();

        $application = Application::factory()
            ->forProject($project, $project->environments()->firstOrFail(), $server)
            ->active()
            ->create(['name' => 'private-app']);

        $otherOwner = User::factory()->create();
        $otherOrganization = $this->organizationOwnedBy($otherOwner);

        $this->actingIn($otherOrganization, $otherOwner)
            ->get(route('applications.show', [$project, $application]))
            ->assertNotFound();
    }

    public function test_application_reconcile_fails_an_expired_deploy(): void
    {
        [$owner, , $project, $server] = $this->context();

        $application = $this->deployableApplication($project, $server, $owner);

        AgentCommand::query()
            ->where('command_id', $application->last_command_id)
            ->update(['expires_at' => now()->subMinute()]);

        Artisan::call('applications:reconcile');

        $this->assertSame(ApplicationStatus::Failed, $application->refresh()->status);
        $this->assertNotNull($application->last_error);
        $this->assertSame(
            DeploymentStatus::Failed,
            $application->latestDeployment->refresh()->status,
        );
        $this->assertSame(
            AgentCommandStatus::Expired,
            AgentCommand::query()->where('command_id', $application->last_command_id)->sole()->status,
        );
    }

    private function deployableApplication(Project $project, Server $server, User $owner): Application
    {
        return app(CreateApplication::class)->execute(
            $project,
            $project->environments()->firstOrFail(),
            $server,
            'web-'.fake()->unique()->numerify('###'),
            'nginx:1.27-alpine',
            80,
            '/',
            $owner,
        );
    }

    private function completeDeployment(
        Application $application,
        Server $server,
        Deployment $deployment,
        string $containerId,
        int $port,
        string $logs,
    ): Deployment {
        $application->refresh();

        $command = AgentCommand::query()
            ->where('command_id', $application->last_command_id)
            ->sole();

        $result = [
            'application_id' => $application->id,
            'deployment_id' => $deployment->id,
            'container_id' => $containerId,
            'container_name' => $deployment->container_name,
            'host' => '127.0.0.1',
            'port' => $port,
            'status' => 'running',
            'health' => 'healthy',
            'previous_container_removed' => true,
            'logs' => $logs,
            'warnings' => [],
        ];

        app(AgentCommandService::class)->complete($command, $server, true, $result);
        app(HandleApplicationCommandResult::class)->handle($command->refresh(), true, $result, null);

        return $deployment->refresh();
    }

    private function completeDeploymentFailure(
        Application $application,
        Server $server,
        Deployment $deployment,
        string $logs,
    ): void {
        $application->refresh();

        $command = AgentCommand::query()
            ->where('command_id', $application->last_command_id)
            ->sole();

        $result = [
            'application_id' => $application->id,
            'deployment_id' => $deployment->id,
            'status' => 'failed',
            'health' => 'unhealthy',
            'logs' => $logs,
            'warnings' => [],
        ];

        app(AgentCommandService::class)->complete(
            $command,
            $server,
            false,
            $result,
            'The application did not become healthy.',
        );
        app(HandleApplicationCommandResult::class)->handle(
            $command->refresh(),
            false,
            $result,
            'The application did not become healthy.',
        );
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
