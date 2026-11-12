<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerEnrollment;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Models\AuditLog;
use App\Domains\Organizations\Models\OrganizationApiToken;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAgents;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use InteractsWithAgents, InteractsWithOrganizations, RefreshDatabase;

    public function test_api_requires_a_bearer_token(): void
    {
        $this->getJson(route('api.me'))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated')
            ->assertJsonPath('message', 'A CloudForge organization API token is required.');
    }

    public function test_api_rejects_tokens_without_the_cloudforge_prefix(): void
    {
        $this->getJson(route('api.me'), ['Authorization' => 'Bearer nope'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_api_rejects_unknown_tokens(): void
    {
        $this->getJson(route('api.me'), $this->bearer('cf_'.str_repeat('a', 48)))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'The API token is invalid or expired.');
    }

    public function test_api_rejects_expired_tokens(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $token = $this->issueApiToken($organization, ['*'], now()->subMinute());

        $this->getJson(route('api.me'), $this->bearer($token))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'The API token is invalid or expired.');
    }

    public function test_api_records_token_usage_and_returns_context(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $token = $this->issueApiToken($organization, ['servers:read', 'projects:read'], null, $owner);

        $this->getJson(route('api.me'), $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('organization.id', $organization->id)
            ->assertJsonPath('token.name', 'Test token')
            ->assertJsonPath('token.abilities', ['servers:read', 'projects:read']);

        $model = OrganizationApiToken::query()->sole();

        $this->assertNotNull($model->last_used_at);
        $this->assertSame(OrganizationApiToken::hashToken($token), $model->token_hash);
        $this->assertNotSame($token, $model->token_hash);
    }

    public function test_token_abilities_are_enforced(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $token = $this->issueApiToken($organization, ['projects:read']);

        $this->getJson(route('api.servers.index'), $this->bearer($token))
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden')
            ->assertJsonPath('required_ability', 'servers:read');

        $this->getJson(route('api.projects.index'), $this->bearer($token))
            ->assertOk();
    }

    public function test_suffixed_wildcard_abilities_grant_matching_access(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $token = $this->issueApiToken($organization, ['servers:*']);

        $this->getJson(route('api.servers.index'), $this->bearer($token))->assertOk();
        $this->getJson(route('api.projects.index'), $this->bearer($token))->assertForbidden();
    }

    public function test_server_listing_and_show_are_scoped_to_the_token_organization(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $otherOrganization = $this->organizationOwnedBy(User::factory()->create());

        $own = Server::factory()->forOrganization($organization)->create(['name' => 'own-node']);
        $foreign = Server::factory()->forOrganization($otherOrganization)->create(['name' => 'foreign-node']);

        $token = $this->issueApiToken($organization, ['servers:read']);

        $this->getJson(route('api.servers.index'), $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonCount(1, 'data');

        $this->getJson(route('api.servers.show', $foreign->id), $this->bearer($token))
            ->assertNotFound();
    }

    public function test_server_crud_lifecycle_updates_audit_logs(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $token = $this->issueApiToken($organization, ['servers:read', 'servers:write'], null, $owner);

        $created = $this->postJson(route('api.servers.store'), [
            'name' => 'api-node',
            'hostname' => 'api-node.example.com',
            'public_ip' => '203.0.113.10',
            'private_ip' => '10.0.0.10',
            'region' => 'fsn1',
            'roles' => ['general', 'application'],
        ], $this->bearer($token))
            ->assertCreated()
            ->assertJsonPath('data.name', 'api-node')
            ->assertJsonPath('data.status', ServerStatus::InstallationPending->value)
            ->assertJsonCount(2, 'data.roles');

        $serverId = $created->json('data.id');

        $this->patchJson(route('api.servers.update', $serverId), [
            'name' => 'api-node-renamed',
            'region' => 'nbg1',
        ], $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('data.name', 'api-node-renamed')
            ->assertJsonPath('data.region', 'nbg1');

        $this->deleteJson(route('api.servers.destroy', $serverId), [], $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertSoftDeleted('servers', ['id' => $serverId]);

        foreach ([
            AuditAction::ServerCreated,
            AuditAction::ServerUpdated,
            AuditAction::ServerDeleted,
        ] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action->value,
                'organization_id' => $organization->id,
                'resource_id' => $serverId,
                'actor_id' => $owner->id,
            ]);
        }
    }

    public function test_server_names_are_unique_per_organization(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $otherOrganization = $this->organizationOwnedBy(User::factory()->create());

        Server::factory()->forOrganization($organization)->create(['name' => 'taken']);
        $foreign = Server::factory()->forOrganization($otherOrganization)->create(['name' => 'api-node']);

        $token = $this->issueApiToken($organization, ['servers:write', 'servers:read']);

        $this->postJson(route('api.servers.store'), [
            'name' => 'taken',
            'hostname' => 'duplicate.example.com',
        ], $this->bearer($token))
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'This organization already has a server with that name.');

        $this->postJson(route('api.servers.store'), [
            'name' => 'api-node',
            'hostname' => 'unique.example.com',
        ], $this->bearer($token))->assertCreated();

        $otherToken = $this->issueApiToken($otherOrganization, ['servers:read']);

        $this->getJson(route('api.servers.show', $foreign->id), $this->bearer($otherToken))
            ->assertOk()
            ->assertJsonPath('data.id', $foreign->id);
    }

    public function test_api_issues_enrollment_tokens_and_install_commands(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $server = Server::factory()->forOrganization($organization)->create();

        $token = $this->issueApiToken($organization, ['servers:write'], null, $owner);

        $response = $this->postJson(
            route('api.servers.enrollment', $server->id),
            [],
            $this->bearer($token),
        );

        $response->assertCreated()
            ->assertJsonStructure(['enrollment_token', 'expires_at', 'install_command']);

        $plaintext = $response->json('enrollment_token');
        $enrollment = ServerEnrollment::query()->sole();

        $this->assertSame(ServerEnrollment::hashToken($plaintext), $enrollment->token_hash);
        $this->assertStringContainsString('CLOUDFORGE_ENROLLMENT_TOKEN=', $response->json('install_command'));
        $this->assertStringContainsString($plaintext, $response->json('install_command'));
        $this->assertSame(ServerStatus::InstallationPending, $server->refresh()->status);
    }

    public function test_api_revokes_server_certificates(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $server = Server::factory()->forOrganization($organization)->ready()->create();

        $this->issueServerCertificate($server);

        $token = $this->issueApiToken($organization, ['servers:write'], null, $owner);

        $this->postJson(
            route('api.servers.certificates.revoke', $server->id),
            ['reason' => 'key compromise'],
            $this->bearer($token),
        )
            ->assertOk()
            ->assertJsonPath('revoked', 1);

        $this->assertSame(
            1,
            $server->certificates()->whereNotNull('revoked_at')->count(),
        );
        $this->assertSame(ServerStatus::Error, $server->refresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ServerCertificatesRevoked->value,
            'organization_id' => $organization->id,
            'resource_id' => $server->id,
        ]);

        $this->postJson(
            route('api.servers.certificates.revoke', $server->id),
            ['reason' => ''],
            $this->bearer($token),
        )->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_api_creates_projects_with_a_default_production_environment(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $token = $this->issueApiToken($organization, ['projects:read', 'projects:write']);

        $response = $this->postJson(route('api.projects.store'), [
            'name' => 'Storefront',
            'description' => 'Customer-facing commerce app',
        ], $this->bearer($token));

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Storefront')
            ->assertJsonPath('data.slug', 'storefront')
            ->assertJsonPath('data.environments.0.slug', 'production')
            ->assertJsonPath('data.environments.0.type', 'production');

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ProjectCreated->value,
            'organization_id' => $organization->id,
        ]);

        $this->getJson(route('api.projects.index'), $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'storefront')
            ->assertJsonCount(1, 'data');
    }

    public function test_api_updates_and_deletes_projects(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $token = $this->issueApiToken($organization, ['projects:read', 'projects:write']);

        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Initial',
            'slug' => 'initial',
        ]);

        $this->patchJson(route('api.projects.update', $project->id), [
            'name' => 'Renamed project',
        ], $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed project')
            ->assertJsonPath('data.slug', 'renamed-project');

        $this->deleteJson(route('api.projects.destroy', $project->id), [], $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertSoftDeleted('projects', ['id' => $project->id]);

        foreach ([AuditAction::ProjectUpdated, AuditAction::ProjectDeleted] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action->value,
                'organization_id' => $organization->id,
                'resource_id' => $project->id,
            ]);
        }
    }

    public function test_environment_creation_rejects_duplicate_slugs(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $token = $this->issueApiToken($organization, ['projects:write']);

        $this->postJson(route('api.projects.environments.store', $project->id), [
            'name' => 'Staging',
            'type' => 'staging',
        ], $this->bearer($token))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'staging')
            ->assertJsonPath('data.type', 'staging');

        $this->postJson(route('api.projects.environments.store', $project->id), [
            'name' => 'staging',
            'type' => 'staging',
        ], $this->bearer($token))
            ->assertStatus(422)
            ->assertJsonPath('code', 'environment_exists');

        $this->assertSame(1, $project->environments()->where('slug', 'staging')->count());
    }

    public function test_project_access_is_scoped_to_the_token_organization(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $otherOrganization = $this->organizationOwnedBy(User::factory()->create());
        $foreign = Project::factory()->create(['organization_id' => $otherOrganization->id]);
        $foreign->forceFill(['name' => 'Production'])->save();

        $token = $this->issueApiToken($organization, ['projects:read', 'projects:write']);

        $this->getJson(route('api.projects.show', $foreign->id), $this->bearer($token))
            ->assertNotFound();

        $this->patchJson(route('api.projects.update', $foreign->id), [
            'name' => 'hijacked',
        ], $this->bearer($token))->assertNotFound();

        $this->postJson(route('api.projects.environments.store', $foreign->id), [
            'name' => 'staging',
            'type' => 'staging',
        ], $this->bearer($token))->assertNotFound();

        $this->assertSame('Production', $foreign->refresh()->name);
    }

    public function test_audit_log_api_requires_the_audit_read_ability_and_is_organization_scoped(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $otherOrganization = $this->organizationOwnedBy(User::factory()->create());

        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'action' => AuditAction::ServerCreated->value,
            'resource_type' => 'server',
            'resource_id' => 'own-resource',
            'created_at' => now(),
        ]);

        AuditLog::query()->create([
            'organization_id' => $otherOrganization->id,
            'action' => AuditAction::ServerCreated->value,
            'resource_type' => 'server',
            'resource_id' => 'foreign-resource',
            'created_at' => now(),
        ]);

        $readOnly = $this->issueApiToken($organization, ['servers:read']);

        $this->getJson(route('api.audit-logs.index'), $this->bearer($readOnly))
            ->assertForbidden()
            ->assertJsonPath('required_ability', 'audit:read');

        $auditToken = $this->issueApiToken($organization, ['audit:read']);

        $this->getJson(route('api.audit-logs.index'), $this->bearer($auditToken))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.resource_id', 'own-resource');

        $this->getJson(
            route('api.audit-logs.index', ['action' => AuditAction::ProjectCreated->value]),
            $this->bearer($auditToken),
        )
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
