<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Models\AuditLog;
use App\Domains\Organizations\Enums\OrganizationRole;
use App\Domains\Organizations\Models\Membership;
use App\Domains\Organizations\Models\OrganizationApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use InteractsWithOrganizations, RefreshDatabase;

    public function test_audit_log_page_requires_authentication(): void
    {
        $this->get(route('audit-log.index'))->assertRedirect(route('login'));
    }

    public function test_owners_and_admins_can_view_audit_entries_for_their_organization(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $admin = $this->memberWithRole($organization, OrganizationRole::Admin);
        $otherOrganization = $this->organizationOwnedBy(User::factory()->create());

        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'actor_id' => $owner->id,
            'action' => AuditAction::ServerCreated->value,
            'resource_type' => 'Server',
            'resource_id' => 'own-resource',
            'created_at' => now(),
        ]);

        AuditLog::query()->create([
            'organization_id' => $otherOrganization->id,
            'action' => AuditAction::ServerCreated->value,
            'resource_type' => 'Server',
            'resource_id' => 'foreign-resource',
            'created_at' => now(),
        ]);

        $this->actingIn($organization, $owner)
            ->get(route('audit-log.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('AuditLog/Index')
                ->has('logs.data', 1)
                ->where('logs.total', 1)
                ->where('logs.data.0.resource_id', 'own-resource')
                ->where('logs.data.0.action', AuditAction::ServerCreated->value)
                ->where('logs.data.0.actor', $owner->name)
                ->where('filters.action', '')
                ->has('actions', count(AuditAction::cases())));

        $this->actingIn($organization, $admin)
            ->get(route('audit-log.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('AuditLog/Index')
                ->has('logs.data', 1));
    }

    public function test_only_privileged_roles_can_view_the_audit_log(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());

        foreach ([OrganizationRole::Operator, OrganizationRole::Developer, OrganizationRole::Viewer] as $role) {
            $member = $this->memberWithRole($organization, $role);

            $this->actingIn($organization, $member)
                ->get(route('audit-log.index'))
                ->assertForbidden();
        }
    }

    public function test_audit_log_can_be_filtered_by_action(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);

        foreach ([
            AuditAction::ServerCreated,
            AuditAction::ProjectCreated,
            AuditAction::ServerCreated,
        ] as $action) {
            AuditLog::query()->create([
                'organization_id' => $organization->id,
                'action' => $action->value,
                'created_at' => now(),
            ]);
        }

        $this->actingIn($organization, $owner)
            ->get(route('audit-log.index', ['action' => AuditAction::ServerCreated->value]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs.data', 2)
                ->where('logs.total', 2)
                ->where('filters.action', AuditAction::ServerCreated->value));

        $this->actingIn($organization, $owner)
            ->get(route('audit-log.index', ['action' => AuditAction::ProjectDeleted->value]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs.data', 0)
                ->where('logs.total', 0));
    }

    public function test_audit_log_falls_back_to_a_system_actor_when_none_is_recorded(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);

        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'actor_id' => null,
            'action' => AuditAction::ServerUnreachable->value,
            'created_at' => now()->subMinute(),
        ]);

        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'actor_id' => $owner->id,
            'action' => AuditAction::ServerCreated->value,
            'metadata' => ['name' => 'audit-node'],
            'ip_address' => '203.0.113.7',
            'created_at' => now(),
        ]);

        $this->actingIn($organization, $owner)
            ->get(route('audit-log.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs.data', 2)
                ->where('logs.data.0.actor', $owner->name)
                ->where('logs.data.0.metadata.name', 'audit-node')
                ->where('logs.data.0.ip_address', '203.0.113.7')
                ->where('logs.data.1.actor', 'System')
                ->where('logs.data.1.action', AuditAction::ServerUnreachable->value));
    }

    public function test_audit_log_is_paginated_at_fifty_entries(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);

        foreach (range(1, 55) as $index) {
            AuditLog::query()->create([
                'organization_id' => $organization->id,
                'action' => AuditAction::ServerHeartbeat->value,
                'created_at' => now()->subSeconds($index),
            ]);
        }

        $this->actingIn($organization, $owner)
            ->get(route('audit-log.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs.data', 50)
                ->where('logs.total', 55)
                ->where('logs.per_page', 50));
    }

    public function test_member_role_changes_and_removals_are_audited(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $developer = $this->memberWithRole($organization, OrganizationRole::Developer);
        $membership = Membership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $developer->id)
            ->sole();

        $this->actingIn($organization, $owner)
            ->patch(route('members.update', $membership), [
                'role' => OrganizationRole::Viewer->value,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $roleChange = AuditLog::query()
            ->where('action', AuditAction::MemberRoleChanged->value)
            ->sole();

        $this->assertSame($owner->id, $roleChange->actor_id);
        $this->assertSame($organization->id, $roleChange->organization_id);
        $this->assertSame($membership->id, $roleChange->resource_id);
        $this->assertSame(['from' => 'developer', 'to' => 'viewer'], $roleChange->metadata);

        $this->actingIn($organization, $owner)
            ->delete(route('members.destroy', $membership))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('organization_members', ['id' => $membership->id]);

        $removal = AuditLog::query()
            ->where('action', AuditAction::MemberRemoved->value)
            ->sole();

        $this->assertSame($owner->id, $removal->actor_id);
        $this->assertSame($membership->id, $removal->resource_id);
        $this->assertSame($developer->email, $removal->metadata['email']);
        $this->assertSame('viewer', $removal->metadata['role']);
    }

    public function test_api_token_creation_and_revocation_are_audited(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);

        $this->actingIn($organization, $owner)
            ->post(route('api-tokens.store'), [
                'name' => 'Deployment token',
                'abilities' => ['servers:read', 'projects:read'],
            ])
            ->assertRedirect()
            ->assertSessionHas('plaintext_token');

        $token = OrganizationApiToken::query()->sole();

        $created = AuditLog::query()
            ->where('action', AuditAction::ApiTokenCreated->value)
            ->sole();

        $this->assertSame($owner->id, $created->actor_id);
        $this->assertSame($token->id, $created->resource_id);
        $this->assertSame('Deployment token', $created->metadata['name']);
        $this->assertSame(['servers:read', 'projects:read'], $created->metadata['abilities']);

        $this->actingIn($organization, $owner)
            ->delete(route('api-tokens.destroy', $token))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('organization_api_tokens', ['id' => $token->id]);

        $revoked = AuditLog::query()
            ->where('action', AuditAction::ApiTokenRevoked->value)
            ->sole();

        $this->assertSame($owner->id, $revoked->actor_id);
        $this->assertSame($token->id, $revoked->resource_id);
        $this->assertSame('Deployment token', $revoked->metadata['name']);
    }

    public function test_server_lifecycle_actions_are_audited(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);

        $this->actingIn($organization, $owner)
            ->post(route('servers.store'), [
                'name' => 'audit-node',
                'hostname' => 'audit-node.example.com',
                'roles' => ['application'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $server = Server::query()->sole();

        $this->actingIn($organization, $owner)
            ->post(route('servers.enrollment', $server))
            ->assertRedirect()
            ->assertSessionHas('enrollment_token');

        $ready = Server::factory()->forOrganization($organization)->ready()->create();

        $this->actingIn($organization, $owner)
            ->post(route('servers.state', $ready), [
                'status' => ServerStatus::Maintenance->value,
                'confirm' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingIn($organization, $owner)
            ->delete(route('servers.destroy', $server))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('servers', ['id' => $server->id]);

        $created = AuditLog::query()
            ->where('action', AuditAction::ServerCreated->value)
            ->where('resource_id', $server->id)
            ->sole();

        $this->assertSame($owner->id, $created->actor_id);
        $this->assertSame($organization->id, $created->organization_id);
        $this->assertSame('Server', $created->resource_type);
        $this->assertSame('audit-node', $created->metadata['name']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ServerEnrollmentIssued->value,
            'organization_id' => $organization->id,
            'resource_id' => $server->id,
            'actor_id' => $owner->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ServerMaintenance->value,
            'organization_id' => $organization->id,
            'resource_id' => $ready->id,
            'actor_id' => $owner->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ServerDeleted->value,
            'organization_id' => $organization->id,
            'resource_id' => $server->id,
            'actor_id' => $owner->id,
        ]);
    }

    public function test_api_audit_log_serializes_actor_details(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);

        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'actor_id' => null,
            'action' => AuditAction::ServerUnreachable->value,
            'created_at' => now()->subMinute(),
        ]);

        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'actor_id' => $owner->id,
            'action' => AuditAction::ServerCreated->value,
            'resource_type' => 'Server',
            'resource_id' => 'audit-resource',
            'metadata' => ['name' => 'audit-node'],
            'created_at' => now(),
        ]);

        $token = $this->issueApiToken($organization, ['audit:read']);

        $this->getJson(route('api.audit-logs.index'), $this->bearer($token))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.actor.id', $owner->id)
            ->assertJsonPath('data.0.actor.name', $owner->name)
            ->assertJsonPath('data.0.actor.email', $owner->email)
            ->assertJsonPath('data.0.resource_id', 'audit-resource')
            ->assertJsonPath('data.0.metadata.name', 'audit-node')
            ->assertJsonPath('data.1.actor', null)
            ->assertJsonPath('data.1.action', AuditAction::ServerUnreachable->value);
    }
}
