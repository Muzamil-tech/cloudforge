<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Actions\ClaimBootstrapServers;
use App\Domains\Infrastructure\Actions\IssueEnrollmentToken;
use App\Domains\Infrastructure\Actions\PrepareBootstrapServer;
use App\Domains\Infrastructure\Enums\EnrollmentPurpose;
use App\Domains\Infrastructure\Enums\ServerRoleType;
use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerEnrollment;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Organizations\Actions\CreateOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithAgents;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class BootstrapProvisioningTest extends TestCase
{
    use InteractsWithAgents, InteractsWithOrganizations, RefreshDatabase;

    private const MACHINE_ID = '5c9f0f7a6e0e4d439f0f2b1c3d4e5f60';

    public function test_provisioning_creates_an_unclaimed_bootstrap_server_and_scoped_enrollment(): void
    {
        $result = app(PrepareBootstrapServer::class)->execute($this->facts(), 'primary-node');

        $server = $result['server'];

        $this->assertTrue($result['created']);
        $this->assertFalse($result['already_enrolled']);
        $this->assertNotNull($result['token']);
        $this->assertNull($server->organization_id);
        $this->assertTrue($server->isBootstrap());
        $this->assertNull($server->claimed_at);
        $this->assertSame(self::MACHINE_ID, $server->machine_id);
        $this->assertSame('primary-node', $server->name);
        $this->assertSame(ServerStatus::InstallationPending, $server->status);
        $this->assertSame('203.0.113.10', $server->public_ip);
        $this->assertSame('10.0.0.5', $server->private_ip);
        $this->assertSame(
            [ServerRoleType::Application->value, ServerRoleType::Control->value],
            $server->roles()->pluck('role')->map->value->sort()->values()->all(),
        );

        $enrollment = $result['enrollment'];

        $this->assertNotNull($enrollment);
        $this->assertSame(EnrollmentPurpose::Bootstrap, $enrollment->purpose);
        $this->assertNull($enrollment->organization_id);
        $this->assertNull($enrollment->consumed_at);
        $this->assertSame(ServerEnrollment::hashToken($result['token']), $enrollment->token_hash);
        $this->assertNotSame($result['token'], $enrollment->token_hash);
        $this->assertTrue($enrollment->expires_at->between(now()->addMinutes(29), now()->addMinutes(31)));

        $provisionedAudit = \DB::table('audit_logs')
            ->where('action', AuditAction::ServerBootstrapProvisioned->value)
            ->where('resource_id', $server->id)
            ->sole();

        $this->assertNull($provisionedAudit->organization_id);
        $this->assertStringNotContainsString($result['token'], (string) $provisionedAudit->metadata);
        $this->assertStringNotContainsString(self::MACHINE_ID, (string) $provisionedAudit->metadata);
    }

    public function test_provisioning_is_idempotent_by_machine_id(): void
    {
        $prepare = app(PrepareBootstrapServer::class);
        $first = $prepare->execute($this->facts(), 'primary-node');
        $firstToken = $first['token'];

        $second = $prepare->execute(
            $this->facts(['hostname' => 'renamed.example.com', 'cpu_cores' => 8]),
            'renamed-node',
        );

        $this->assertSame($first['server']->id, $second['server']->id);
        $this->assertFalse($second['created']);
        $this->assertSame(1, Server::query()->count());
        $this->assertSame('renamed.example.com', $second['server']->hostname);
        $this->assertSame(8, $second['server']->cpu_cores);
        $this->assertSame('renamed-node', $second['server']->name);
        $this->assertSame(2, $second['server']->roles()->count());
        $this->assertSame(1, ServerEnrollment::query()->whereNull('consumed_at')->count());
        $this->assertNotNull(ServerEnrollment::query()->where('token_hash', ServerEnrollment::hashToken($firstToken))->sole()->consumed_at);
        $this->assertNotSame($firstToken, $second['token']);
    }

    public function test_provisioning_honours_the_configured_token_ttl(): void
    {
        config(['cloudforge.bootstrap.token_ttl_minutes' => 5]);

        $result = app(PrepareBootstrapServer::class)->execute($this->facts());

        $this->assertTrue($result['enrollment']->expires_at->between(now()->addMinutes(4), now()->addMinutes(6)));
    }

    public function test_provisioning_rejects_a_claimed_server_without_an_active_certificate(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $server = Server::factory()->create([
            'organization_id' => $organization->id,
            'machine_id' => self::MACHINE_ID,
            'is_bootstrap' => true,
        ]);

        try {
            app(PrepareBootstrapServer::class)->execute($this->facts());
            $this->fail('Expected a claimed bootstrap server to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('server', $exception->errors());
        }

        $this->assertSame(0, ServerEnrollment::query()->count());
    }

    public function test_provisioning_rejects_a_machine_id_owned_by_a_manual_server(): void
    {
        Server::factory()->create([
            'machine_id' => self::MACHINE_ID,
            'is_bootstrap' => false,
        ]);

        try {
            app(PrepareBootstrapServer::class)->execute($this->facts());
            $this->fail('Expected a manually registered machine-id to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('machine_id', $exception->errors());
        }
    }

    public function test_provisioning_requires_a_valid_machine_id(): void
    {
        foreach (['', 'uninitialized', str_repeat('z', 32)] as $machineId) {
            try {
                app(PrepareBootstrapServer::class)->execute($this->facts(['machine_id' => $machineId]));
                $this->fail('Expected the machine-id to be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('machine_id', $exception->errors());
            }
        }

        $this->assertSame(0, Server::query()->count());
    }

    public function test_provisioning_returns_the_existing_identity_when_already_enrolled(): void
    {
        $first = app(PrepareBootstrapServer::class)->execute($this->facts());
        $server = $first['server'];

        $this->issueServerCertificate($server);

        $second = app(PrepareBootstrapServer::class)->execute($this->facts());

        $this->assertTrue($second['already_enrolled']);
        $this->assertNull($second['enrollment']);
        $this->assertNull($second['token']);
        $this->assertFalse($second['created']);
        $this->assertSame($server->id, $second['server']->id);
        $this->assertSame(1, Server::query()->count());
        $this->assertSame(1, ServerEnrollment::query()->count());
    }

    public function test_bootstrap_enrollment_rejects_a_different_machine_id_and_leaves_the_token_usable(): void
    {
        $result = app(PrepareBootstrapServer::class)->execute($this->facts());
        $server = $result['server'];

        [, $csrPem] = $this->generateCsr($server->id);

        $response = $this->postJson(route('agent.enroll'), [
            'token' => $result['token'],
            'csr' => $csrPem,
            'protocol_version' => '1.0',
            'agent_version' => '1.0.0',
            'hostname' => $server->hostname,
            'machine_id' => '0123456789abcdef0123456789abcdef',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'enrollment_failed')
            ->assertJsonPath('errors.machine_id.0', 'This bootstrap enrollment token is not valid for this host.');

        $this->assertNull($result['enrollment']->refresh()->consumed_at);
        $this->assertSame(0, $server->certificates()->count());

        $retry = $this->postJson(route('agent.enroll'), [
            'token' => $result['token'],
            'csr' => $csrPem,
            'protocol_version' => '1.0',
            'agent_version' => '1.0.0',
            'hostname' => $server->hostname,
            'machine_id' => self::MACHINE_ID,
        ]);

        $retry->assertCreated();
        $this->assertNotNull($result['enrollment']->refresh()->consumed_at);
        $this->assertSame(1, $server->certificates()->count());
    }

    public function test_bootstrap_enrollment_requires_a_machine_id(): void
    {
        $result = app(PrepareBootstrapServer::class)->execute($this->facts());
        [, $csrPem] = $this->generateCsr($result['server']->id);

        $response = $this->postJson(route('agent.enroll'), [
            'token' => $result['token'],
            'csr' => $csrPem,
            'protocol_version' => '1.0',
            'agent_version' => '1.0.0',
            'hostname' => $result['server']->hostname,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.machine_id.0', 'This bootstrap enrollment token is not valid for this host.');
    }

    public function test_bootstrap_enrollment_auto_claims_when_exactly_one_organization_exists(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $result = app(PrepareBootstrapServer::class)->execute($this->facts());
        $server = $result['server'];

        [, $csrPem] = $this->generateCsr($server->id);

        $this->postJson(route('agent.enroll'), [
            'token' => $result['token'],
            'csr' => $csrPem,
            'protocol_version' => '1.0',
            'agent_version' => '1.0.0',
            'hostname' => $server->hostname,
            'machine_id' => self::MACHINE_ID,
        ])->assertCreated();

        $server->refresh();

        $this->assertSame($organization->id, $server->organization_id);
        $this->assertNotNull($server->claimed_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ServerBootstrapClaimed->value,
            'organization_id' => $organization->id,
            'resource_id' => $server->id,
        ]);
    }

    public function test_bootstrap_enrollment_does_not_auto_claim_when_multiple_organizations_exist(): void
    {
        $this->organizationOwnedBy(User::factory()->create());
        $this->organizationOwnedBy(User::factory()->create());

        $result = app(PrepareBootstrapServer::class)->execute($this->facts());
        $server = $result['server'];

        [, $csrPem] = $this->generateCsr($server->id);

        $this->postJson(route('agent.enroll'), [
            'token' => $result['token'],
            'csr' => $csrPem,
            'protocol_version' => '1.0',
            'agent_version' => '1.0.0',
            'hostname' => $server->hostname,
            'machine_id' => self::MACHINE_ID,
        ])->assertCreated();

        $server->refresh();

        $this->assertNull($server->organization_id);
        $this->assertNull($server->claimed_at);
    }

    public function test_claim_action_claims_only_unclaimed_bootstrap_servers(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);

        $first = Server::factory()->create([
            'organization_id' => null,
            'machine_id' => '0123456789abcdef0123456789abcdef',
            'is_bootstrap' => true,
        ]);
        $second = Server::factory()->create([
            'organization_id' => null,
            'machine_id' => 'fedcba9876543210fedcba9876543210',
            'is_bootstrap' => true,
        ]);
        $manual = Server::factory()->forOrganization($organization)->create();

        $claimed = app(ClaimBootstrapServers::class)->execute($organization, $owner, $first);

        $this->assertSame([$first->id], $claimed->pluck('id')->all());
        $this->assertSame($organization->id, $first->refresh()->organization_id);
        $firstClaimedAt = $first->claimed_at;
        $this->assertNull($second->refresh()->organization_id);
        $this->assertSame($organization->id, $manual->refresh()->organization_id);

        $remaining = app(ClaimBootstrapServers::class)->execute($organization, $owner);

        $this->assertSame([$second->id], $remaining->pluck('id')->all());
        $this->assertSame($organization->id, $first->refresh()->organization_id);
        $this->assertTrue($firstClaimedAt?->equalTo($first->claimed_at) ?? false);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ServerBootstrapClaimed->value,
            'organization_id' => $organization->id,
            'actor_id' => $owner->id,
            'resource_id' => $second->id,
        ]);
    }

    public function test_creating_an_organization_claims_unclaimed_bootstrap_servers(): void
    {
        $owner = User::factory()->create();

        $server = Server::factory()->create([
            'organization_id' => null,
            'machine_id' => self::MACHINE_ID,
            'is_bootstrap' => true,
        ]);

        $organization = app(CreateOrganization::class)->execute($owner, ['name' => 'Acme Cloud']);

        $server->refresh();

        $this->assertSame($organization->id, $server->organization_id);
        $this->assertNotNull($server->claimed_at);
    }

    public function test_bootstrap_tokens_cannot_be_issued_for_a_claimed_server(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $server = Server::factory()->forOrganization($organization)->create();

        $this->expectException(ValidationException::class);

        app(IssueEnrollmentToken::class)->execute($server, null, EnrollmentPurpose::Bootstrap);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function facts(array $overrides = []): array
    {
        return array_merge([
            'hostname' => 'control.example.com',
            'operating_system' => 'Ubuntu 24.04 LTS',
            'architecture' => 'x86_64',
            'cpu_cores' => 4,
            'total_memory_bytes' => 8 * 1024 ** 3,
            'total_disk_bytes' => 80 * 1024 ** 3,
            'available_disk_bytes' => 60 * 1024 ** 3,
            'addresses' => ['203.0.113.10', '10.0.0.5'],
            'public_ip' => '203.0.113.10',
            'private_ip' => '10.0.0.5',
            'docker_version' => '27.3.1',
            'machine_id' => self::MACHINE_ID,
        ], $overrides);
    }
}
