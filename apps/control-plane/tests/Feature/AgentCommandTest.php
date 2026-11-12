<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerCertificate;
use App\Domains\Operations\Enums\AgentCommandStatus;
use App\Domains\Operations\Enums\AgentOperation;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Models\AgentCommand;
use App\Domains\Operations\Services\AgentCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSSLAsymmetricKey;
use Tests\Concerns\InteractsWithAgents;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class AgentCommandTest extends TestCase
{
    use InteractsWithAgents, InteractsWithOrganizations, RefreshDatabase;

    public function test_api_issues_a_command_and_returns_accepted(): void
    {
        [$server, $token] = $this->serverWithToken(['commands:write']);

        $response = $this->postJson(
            route('api.servers.commands.store', $server->id),
            [
                'operation' => AgentOperation::ServerInspect->value,
                'payload' => ['reason' => 'scheduled inspection'],
            ],
            $this->bearer($token),
        );

        $response->assertStatus(202)
            ->assertJsonPath('data.operation', AgentOperation::ServerInspect->value)
            ->assertJsonPath('data.status', AgentCommandStatus::Pending->value)
            ->assertJsonPath('data.payload.reason', 'scheduled inspection');

        $command = AgentCommand::query()->sole();

        $this->assertSame($server->id, $command->server_id);
        $this->assertSame(AgentOperation::ServerInspect, $command->operation);
        $this->assertSame(AgentCommandStatus::Pending, $command->status);
        $this->assertSame(0, $command->attempts);
        $this->assertNotNull($command->expires_at);
        $this->assertTrue($command->expires_at->between(now()->addMinutes(4), now()->addMinutes(6)));

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::AgentCommandIssued->value,
            'organization_id' => $server->organization_id,
            'resource_id' => $command->id,
        ]);
    }

    public function test_agent_poll_receives_a_pending_command(): void
    {
        [$server, $certificate, $key] = $this->agentServer();

        $command = app(AgentCommandService::class)->issue(
            $server,
            AgentOperation::ServerHealth,
            ['reason' => 'poll test'],
        );

        $this->signedAgentRequest($certificate, $key, 'GET', route('agent.commands.next'))
            ->assertOk()
            ->assertJsonPath('command.id', $command->command_id)
            ->assertJsonPath('command.operation', AgentOperation::ServerHealth->value)
            ->assertJsonPath('command.payload.reason', 'poll test')
            ->assertJsonPath('command.attempt', 1);

        $this->assertSame(1, $command->refresh()->attempts);
    }

    public function test_agent_poll_returns_no_content_when_no_commands_exist(): void
    {
        [, $certificate, $key] = $this->agentServer();

        $this->signedAgentRequest($certificate, $key, 'GET', route('agent.commands.next'))
            ->assertNoContent();
    }

    public function test_agent_acknowledge_moves_a_pending_command_to_acknowledged(): void
    {
        [$server, $certificate, $key] = $this->agentServer();

        $command = app(AgentCommandService::class)->issue($server, AgentOperation::MetricsCollect);

        $this->signedAgentRequest(
            $certificate,
            $key,
            'POST',
            route('agent.commands.acknowledge', $command->command_id),
        )
            ->assertOk()
            ->assertJsonPath('status', AgentCommandStatus::Acknowledged->value)
            ->assertJsonPath('command_id', $command->command_id);

        $command->refresh();

        $this->assertSame(AgentCommandStatus::Acknowledged, $command->status);
        $this->assertNotNull($command->acknowledged_at);
        $this->assertNotNull($command->started_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::AgentCommandAcknowledged->value,
            'resource_id' => $command->id,
        ]);
    }

    public function test_agent_reports_a_successful_result(): void
    {
        [$server, $certificate, $key] = $this->agentServer();

        $command = app(AgentCommandService::class)->issue($server, AgentOperation::ServerInspect);

        $this->signedAgentRequest(
            $certificate,
            $key,
            'POST',
            route('agent.commands.acknowledge', $command->command_id),
        )->assertOk();

        $this->signedAgentRequest(
            $certificate,
            $key,
            'POST',
            route('agent.commands.result', $command->command_id),
            [
                'status' => 'completed',
                'result' => ['hostname' => 'node-1.example.com', 'docker' => ['available' => true]],
                'duration_ms' => 1250,
            ],
        )
            ->assertOk()
            ->assertJsonPath('status', AgentCommandStatus::Completed->value);

        $command->refresh();

        $this->assertSame(AgentCommandStatus::Completed, $command->status);
        $this->assertSame('node-1.example.com', $command->result['hostname']);
        $this->assertNull($command->error_message);
        $this->assertNotNull($command->completed_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::AgentCommandCompleted->value,
            'resource_id' => $command->id,
        ]);
    }

    public function test_agent_reports_a_failed_result(): void
    {
        [$server, $certificate, $key] = $this->agentServer();

        $command = app(AgentCommandService::class)->issue($server, AgentOperation::ServerHealth);

        $this->signedAgentRequest(
            $certificate,
            $key,
            'POST',
            route('agent.commands.result', $command->command_id),
            [
                'status' => 'failed',
                'error_message' => 'Docker daemon is not reachable.',
            ],
        )
            ->assertOk()
            ->assertJsonPath('status', AgentCommandStatus::Failed->value);

        $command->refresh();

        $this->assertSame(AgentCommandStatus::Failed, $command->status);
        $this->assertSame('Docker daemon is not reachable.', $command->error_message);
        $this->assertNotNull($command->completed_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::AgentCommandFailed->value,
            'resource_id' => $command->id,
        ]);
    }

    public function test_issuing_the_same_idempotency_key_returns_the_existing_command(): void
    {
        [$server, $token] = $this->serverWithToken(['commands:write']);

        $payload = [
            'operation' => AgentOperation::ServerInspect->value,
            'idempotency_key' => 'deploy-check-42',
        ];

        $first = $this->postJson(
            route('api.servers.commands.store', $server->id),
            $payload,
            $this->bearer($token),
        )->assertStatus(202);

        $second = $this->postJson(
            route('api.servers.commands.store', $server->id),
            $payload,
            $this->bearer($token),
        )->assertStatus(202);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, AgentCommand::query()->count());
    }

    public function test_idempotency_keys_cannot_be_reused_for_a_different_operation(): void
    {
        [$server, $token] = $this->serverWithToken(['commands:write']);

        $this->postJson(
            route('api.servers.commands.store', $server->id),
            [
                'operation' => AgentOperation::ServerInspect->value,
                'idempotency_key' => 'reused-key',
            ],
            $this->bearer($token),
        )->assertStatus(202);

        $this->postJson(
            route('api.servers.commands.store', $server->id),
            [
                'operation' => AgentOperation::AgentRestart->value,
                'idempotency_key' => 'reused-key',
            ],
            $this->bearer($token),
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(1, AgentCommand::query()->count());
    }

    public function test_expired_commands_are_not_delivered_and_are_marked_expired(): void
    {
        [$server, $certificate, $key] = $this->agentServer();

        $command = app(AgentCommandService::class)->issue($server, AgentOperation::ServerHealth);
        $command->forceFill([
            'issued_at' => now()->subMinutes(10),
            'expires_at' => now()->subMinute(),
        ])->save();

        $this->signedAgentRequest($certificate, $key, 'GET', route('agent.commands.next'))
            ->assertNoContent();

        $this->assertSame(AgentCommandStatus::Expired, $command->refresh()->status);
        $this->assertNotNull($command->completed_at);
    }

    public function test_acknowledged_commands_are_redelivered_after_one_minute(): void
    {
        [$server, $certificate, $key] = $this->agentServer();

        $command = app(AgentCommandService::class)->issue($server, AgentOperation::ServerHealth);

        $this->signedAgentRequest($certificate, $key, 'GET', route('agent.commands.next'))
            ->assertOk()
            ->assertJsonPath('command.id', $command->command_id);

        $this->signedAgentRequest(
            $certificate,
            $key,
            'POST',
            route('agent.commands.acknowledge', $command->command_id),
        )->assertOk();

        $this->signedAgentRequest($certificate, $key, 'GET', route('agent.commands.next'))
            ->assertNoContent();

        $this->travel(61)->seconds();

        $this->signedAgentRequest($certificate, $key, 'GET', route('agent.commands.next'))
            ->assertOk()
            ->assertJsonPath('command.id', $command->command_id)
            ->assertJsonPath('command.attempt', 2);

        $this->assertSame(2, $command->refresh()->attempts);
    }

    public function test_cancelled_commands_are_not_delivered(): void
    {
        [$server, $token] = $this->serverWithToken(['commands:write', 'servers:read']);
        [$certificate, $key] = $this->certificateFor($server);

        $command = app(AgentCommandService::class)->issue($server, AgentOperation::ServerInspect);

        $this->postJson(
            route('api.servers.commands.cancel', ['server' => $server->id, 'command' => $command->command_id]),
            [],
            $this->bearer($token),
        )
            ->assertOk()
            ->assertJsonPath('data.status', AgentCommandStatus::Cancelled->value);

        $this->signedAgentRequest($certificate, $key, 'GET', route('agent.commands.next'))
            ->assertNoContent();

        $this->assertSame(AgentCommandStatus::Cancelled, $command->refresh()->status);
        $this->assertNotNull($command->completed_at);
    }

    public function test_terminal_commands_ignore_late_acknowledgements_and_results(): void
    {
        [$server, $certificate, $key] = $this->agentServer();

        $command = app(AgentCommandService::class)->issue($server, AgentOperation::ServerHealth);

        $this->signedAgentRequest(
            $certificate,
            $key,
            'POST',
            route('agent.commands.result', $command->command_id),
            ['status' => 'completed', 'result' => ['healthy' => true]],
        )->assertOk();

        $this->signedAgentRequest(
            $certificate,
            $key,
            'POST',
            route('agent.commands.acknowledge', $command->command_id),
        )
            ->assertOk()
            ->assertJsonPath('status', AgentCommandStatus::Completed->value);

        $this->signedAgentRequest(
            $certificate,
            $key,
            'POST',
            route('agent.commands.result', $command->command_id),
            ['status' => 'failed', 'error_message' => 'late failure'],
        )
            ->assertOk()
            ->assertJsonPath('status', AgentCommandStatus::Completed->value);

        $command->refresh();

        $this->assertSame(AgentCommandStatus::Completed, $command->status);
        $this->assertTrue($command->result['healthy']);
        $this->assertNull($command->error_message);
    }

    public function test_commands_belonging_to_another_server_cannot_be_accessed(): void
    {
        [$server] = $this->serverWithToken([]);
        $otherOrganization = $this->organizationOwnedBy(User::factory()->create());
        $otherServer = Server::factory()->forOrganization($otherOrganization)->create([
            'status' => ServerStatus::Ready,
        ]);

        $command = app(AgentCommandService::class)->issue($server, AgentOperation::ServerInspect);

        [$otherCertificate, $otherKey] = $this->certificateFor($otherServer);

        $this->signedAgentRequest(
            $otherCertificate,
            $otherKey,
            'POST',
            route('agent.commands.acknowledge', $command->command_id),
        )->assertNotFound();

        $this->signedAgentRequest(
            $otherCertificate,
            $otherKey,
            'POST',
            route('agent.commands.result', $command->command_id),
            ['status' => 'completed'],
        )->assertNotFound();

        $this->assertSame(AgentCommandStatus::Pending, $command->refresh()->status);
    }

    public function test_invalid_command_payloads_are_rejected_before_persistence(): void
    {
        [$server, $token] = $this->serverWithToken(['commands:write']);

        $this->postJson(
            route('api.servers.commands.store', $server->id),
            [
                'operation' => AgentOperation::ServerInspect->value,
                'payload' => ['reason' => str_repeat('a', 256)],
            ],
            $this->bearer($token),
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(0, AgentCommand::query()->count());
        $this->assertDatabaseMissing('audit_logs', [
            'action' => AuditAction::AgentCommandIssued->value,
        ]);
    }

    public function test_unknown_operations_are_rejected(): void
    {
        [$server, $token] = $this->serverWithToken(['commands:write']);

        $this->postJson(
            route('api.servers.commands.store', $server->id),
            ['operation' => 'shell.execute'],
            $this->bearer($token),
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors('operation');

        $this->assertSame(0, AgentCommand::query()->count());
    }

    public function test_resource_operations_cannot_be_issued_through_the_generic_command_api(): void
    {
        [$server, $token] = $this->serverWithToken(['commands:write']);

        $this->postJson(
            route('api.servers.commands.store', $server->id),
            [
                'operation' => AgentOperation::ResourceCreate->value,
                'payload' => [
                    'resource_id' => '01a1d058-8612-7209-9082-03adf0d11976',
                    'resource_type' => 'postgresql',
                    'container_name' => 'cloudforge-01a1d058-8612-7209-9082-03adf0d11976',
                    'volume_name' => 'cloudforge-01a1d058-8612-7209-9082-03adf0d11976-data',
                ],
            ],
            $this->bearer($token),
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors('operation');

        $this->assertSame(0, AgentCommand::query()->count());
    }

    public function test_agent_restart_commands_use_a_short_expiry(): void
    {
        [$server] = $this->serverWithToken([]);

        $command = app(AgentCommandService::class)->issue(
            $server,
            AgentOperation::AgentRestart,
            ['reason' => 'manual restart'],
        );

        $this->assertTrue($command->expires_at->between(now()->addSeconds(55), now()->addSeconds(65)));
    }

    public function test_agent_can_list_its_own_commands_through_the_api_with_a_read_token(): void
    {
        [$server, $token] = $this->serverWithToken(['servers:read', 'commands:write']);

        app(AgentCommandService::class)->issue($server, AgentOperation::ServerHealth);

        $this->getJson(
            route('api.servers.commands', $server->id),
            $this->bearer($token),
        )
            ->assertOk()
            ->assertJsonPath('data.0.operation', AgentOperation::ServerHealth->value)
            ->assertJsonPath('data.0.status', AgentCommandStatus::Pending->value);
    }

    /**
     * @param  list<string>  $abilities
     * @return array{0: Server, 1: string}
     */
    private function serverWithToken(array $abilities): array
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $server = Server::factory()->forOrganization($organization)->ready()->create();

        $token = $this->issueApiToken(
            $organization,
            $abilities === [] ? ['*'] : $abilities,
        );

        return [$server, $token];
    }

    /**
     * @return array{0: Server, 1: ServerCertificate, 2: OpenSSLAsymmetricKey}
     */
    private function agentServer(): array
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $server = Server::factory()->forOrganization($organization)->ready()->create();

        [$certificate, $key] = $this->certificateFor($server);

        return [$server, $certificate, $key];
    }

    /**
     * @return array{0: ServerCertificate, 1: OpenSSLAsymmetricKey}
     */
    private function certificateFor(Server $server): array
    {
        $issued = $this->issueServerCertificate($server);

        return [$issued['certificate'], $issued['key']];
    }
}
