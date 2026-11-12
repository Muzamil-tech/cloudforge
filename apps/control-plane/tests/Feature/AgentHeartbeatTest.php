<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerCapability;
use App\Domains\Infrastructure\Models\ServerCertificate;
use App\Domains\Infrastructure\Models\ServerMetric;
use App\Domains\Operations\Enums\AuditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAgents;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class AgentHeartbeatTest extends TestCase
{
    use InteractsWithAgents, InteractsWithOrganizations, RefreshDatabase;

    public function test_heartbeat_promotes_a_registered_agent_to_ready_and_records_capabilities(): void
    {
        [$server, $certificate, $key] = $this->agentServer(ServerStatus::AgentRegistered);

        $this->signedAgentRequest($certificate, $key, 'POST', route('agent.heartbeat'), [
            'agent_version' => '1.4.0',
            'docker_available' => true,
            'docker_version' => '27.4.1',
            'uptime_seconds' => 7200,
            'load_average' => [0.4, 0.3, 0.2],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('server_status', ServerStatus::Ready->value);

        $server->refresh();

        $this->assertSame(ServerStatus::Ready, $server->status);
        $this->assertNotNull($server->last_heartbeat_at);
        $this->assertSame('1.4.0', $server->agent_version);
        $this->assertSame('27.4.1', $server->docker_version);
        $this->assertSame(7200, $server->metadata['last_heartbeat']['uptime_seconds']);
        $this->assertTrue($server->metadata['last_heartbeat']['docker_available']);

        $capability = ServerCapability::query()
            ->where('server_id', $server->id)
            ->where('capability', 'docker')
            ->sole();

        $this->assertSame('27.4.1', $capability->version);
        $this->assertTrue($capability->metadata['available']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ServerHeartbeat->value,
            'organization_id' => $server->organization_id,
            'resource_id' => $server->id,
        ]);
    }

    public function test_heartbeat_without_docker_puts_the_server_in_error(): void
    {
        [$server, $certificate, $key] = $this->agentServer(ServerStatus::AgentRegistered);

        $this->signedAgentRequest($certificate, $key, 'POST', route('agent.heartbeat'), [
            'agent_version' => '1.4.0',
            'docker_available' => false,
        ])
            ->assertOk()
            ->assertJsonPath('server_status', ServerStatus::Error->value);

        $this->assertSame(ServerStatus::Error, $server->refresh()->status);

        $capability = ServerCapability::query()
            ->where('server_id', $server->id)
            ->where('capability', 'docker')
            ->sole();

        $this->assertFalse($capability->metadata['available']);
    }

    public function test_heartbeats_do_not_change_an_active_server_but_refresh_the_timestamp(): void
    {
        [$server, $certificate, $key] = $this->agentServer(ServerStatus::Active, now()->subMinutes(5));

        $this->signedAgentRequest($certificate, $key, 'POST', route('agent.heartbeat'), [
            'agent_version' => '1.4.0',
            'docker_available' => true,
        ])->assertOk()->assertJsonPath('server_status', ServerStatus::Active->value);

        $this->assertSame(ServerStatus::Active, $server->refresh()->status);
        $this->assertTrue($server->last_heartbeat_at->greaterThan(now()->subMinute()));
    }

    public function test_heartbeats_preserve_operator_maintenance_and_draining_states(): void
    {
        foreach ([ServerStatus::Maintenance, ServerStatus::Draining] as $status) {
            [$server, $certificate, $key] = $this->agentServer($status);

            $this->signedAgentRequest($certificate, $key, 'POST', route('agent.heartbeat'), [
                'agent_version' => '1.4.0',
                'docker_available' => true,
            ])->assertOk()->assertJsonPath('server_status', $status->value);

            $this->assertSame($status, $server->refresh()->status);
        }
    }

    public function test_heartbeat_recovers_an_unreachable_server_to_its_previous_state(): void
    {
        [$server, $certificate, $key] = $this->agentServer(ServerStatus::Unreachable);

        $metadata = $server->metadata ?? [];
        $metadata['status_before_unreachable'] = ServerStatus::Active->value;
        $server->forceFill(['metadata' => $metadata])->save();

        $this->signedAgentRequest($certificate, $key, 'POST', route('agent.heartbeat'), [
            'agent_version' => '1.4.0',
            'docker_available' => true,
        ])->assertOk()->assertJsonPath('server_status', ServerStatus::Active->value);

        $server->refresh();

        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertArrayNotHasKey('status_before_unreachable', $server->metadata ?? []);
    }

    public function test_heartbeat_from_unreachable_without_previous_state_becomes_ready(): void
    {
        [$server, $certificate, $key] = $this->agentServer(ServerStatus::Unreachable);

        $this->signedAgentRequest($certificate, $key, 'POST', route('agent.heartbeat'), [
            'agent_version' => '1.4.0',
            'docker_available' => true,
        ])->assertOk()->assertJsonPath('server_status', ServerStatus::Ready->value);

        $this->assertSame(ServerStatus::Ready, $server->refresh()->status);
    }

    public function test_inventory_replaces_server_attributes_and_capabilities(): void
    {
        [$server, $certificate, $key] = $this->agentServer(ServerStatus::Ready);

        ServerCapability::query()->create([
            'server_id' => $server->id,
            'capability' => 'stale',
            'version' => '0.1',
        ]);

        $this->signedAgentRequest($certificate, $key, 'POST', route('agent.inventory'), [
            'hostname' => 'inventory.example.com',
            'operating_system' => 'Debian 13',
            'architecture' => 'arm64',
            'cpu_cores' => 8,
            'total_memory_bytes' => 16 * 1024 ** 3,
            'total_disk_bytes' => 320 * 1024 ** 3,
            'docker_version' => '28.1.0',
            'agent_version' => '1.5.0',
            'collected_at' => now()->toIso8601String(),
            'docker' => [
                'available' => true,
                'version' => '28.1.0',
                'storage_driver' => 'overlay2',
                'rootless' => false,
            ],
            'capabilities' => [
                ['name' => 'docker', 'version' => '28.1.0', 'metadata' => ['rootless' => false]],
                ['name' => 'systemd', 'version' => '257'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $server->refresh();

        $this->assertSame('inventory.example.com', $server->hostname);
        $this->assertSame('Debian 13', $server->operating_system);
        $this->assertSame('arm64', $server->architecture);
        $this->assertSame(8, $server->cpu_cores);
        $this->assertSame(16 * 1024 ** 3, $server->total_memory_bytes);
        $this->assertSame('28.1.0', $server->docker_version);
        $this->assertNotNull($server->last_inventory_at);
        $this->assertSame('overlay2', $server->metadata['inventory']['docker']['storage_driver']);

        $capabilities = ServerCapability::query()
            ->where('server_id', $server->id)
            ->orderBy('capability')
            ->get();

        $this->assertSame(['docker', 'systemd'], $capabilities->pluck('capability')->all());
        $this->assertSame('28.1.0', $capabilities[0]->version);
        $this->assertSame(257, (int) $capabilities[1]->version);
    }

    public function test_metrics_points_are_stored_and_refresh_the_heartbeat_timestamp(): void
    {
        [$server, $certificate, $key] = $this->agentServer(ServerStatus::Active, now()->subMinutes(5));

        $this->signedAgentRequest($certificate, $key, 'POST', route('agent.metrics'), [
            'points' => [
                [
                    'recorded_at' => now()->subMinute()->toIso8601String(),
                    'cpu_usage' => 12.5,
                    'memory_usage' => 40.0,
                    'disk_usage' => 55.5,
                    'memory_used_bytes' => 4 * 1024 ** 3,
                    'disk_used_bytes' => 20 * 1024 ** 3,
                    'network_in_bytes' => 1024,
                    'network_out_bytes' => 2048,
                    'metadata' => ['source' => 'proc'],
                ],
                [
                    'recorded_at' => now()->toIso8601String(),
                    'cpu_usage' => 22.0,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('recorded', 2);

        $this->assertSame(2, ServerMetric::query()->where('server_id', $server->id)->count());

        $metric = ServerMetric::query()
            ->where('server_id', $server->id)
            ->where('cpu_usage', 12.5)
            ->sole();

        $this->assertSame(40.0, $metric->memory_usage);
        $this->assertSame(['source' => 'proc'], $metric->metadata);
        $this->assertTrue($server->refresh()->last_heartbeat_at->greaterThan(now()->subMinute()));
    }

    public function test_metric_payload_limits_are_enforced(): void
    {
        [$server, $certificate, $key] = $this->agentServer(ServerStatus::Active);

        $points = [];

        for ($index = 0; $index < 5; $index++) {
            $points[] = ['recorded_at' => now()->toIso8601String(), 'cpu_usage' => 1.0];
        }

        config(['cloudforge.metrics.max_points_per_request' => 2]);

        $this->signedAgentRequest($certificate, $key, 'POST', route('agent.metrics'), ['points' => $points])
            ->assertStatus(422)
            ->assertJsonValidationErrors('points');

        $this->assertSame(0, ServerMetric::query()->where('server_id', $server->id)->count());
    }

    public function test_mark_unreachable_command_flags_stale_servers_and_audits_the_change(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $stale = Server::factory()->forOrganization($organization)->active()->create([
            'last_heartbeat_at' => now()->subMinutes(10),
        ]);
        $fresh = Server::factory()->forOrganization($organization)->active()->create([
            'last_heartbeat_at' => now()->subSeconds(5),
        ]);

        $this->artisan('servers:mark-unreachable', ['--timeout' => 90])
            ->expectsOutputToContain('1 server(s) marked unreachable.')
            ->assertSuccessful();

        $stale->refresh();

        $this->assertSame(ServerStatus::Unreachable, $stale->status);
        $this->assertSame(ServerStatus::Active->value, $stale->metadata['status_before_unreachable']);
        $this->assertSame(ServerStatus::Active, $fresh->refresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ServerUnreachable->value,
            'organization_id' => $organization->id,
            'resource_id' => $stale->id,
        ]);
    }

    public function test_mark_unreachable_dry_run_does_not_change_state(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $stale = Server::factory()->forOrganization($organization)->active()->create([
            'last_heartbeat_at' => now()->subMinutes(10),
        ]);

        $this->artisan('servers:mark-unreachable', ['--dry-run' => true])
            ->expectsOutputToContain('1 server(s) would be marked unreachable.')
            ->assertSuccessful();

        $this->assertSame(ServerStatus::Active, $stale->refresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => AuditAction::ServerUnreachable->value,
            'resource_id' => $stale->id,
        ]);
    }

    public function test_servers_without_a_heartbeat_are_not_marked_unreachable(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $pending = Server::factory()->forOrganization($organization)->create([
            'status' => ServerStatus::InstallationPending,
            'last_heartbeat_at' => null,
        ]);

        $this->artisan('servers:mark-unreachable')->assertSuccessful();

        $this->assertSame(ServerStatus::InstallationPending, $pending->refresh()->status);
    }

    /**
     * @return array{0: Server, 1: ServerCertificate, 2: \OpenSSLAsymmetricKey}
     */
    private function agentServer(ServerStatus $status, mixed $heartbeatAt = null): array
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $server = Server::factory()->forOrganization($organization)->create([
            'status' => $status,
            'last_heartbeat_at' => $heartbeatAt,
        ]);

        $issued = $this->issueServerCertificate($server);

        return [$server, $issued['certificate'], $issued['key']];
    }
}
