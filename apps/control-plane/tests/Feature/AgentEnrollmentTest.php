<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Actions\IssueEnrollmentToken;
use App\Domains\Infrastructure\Actions\RotateServerCertificate;
use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerCertificate;
use App\Domains\Infrastructure\Models\ServerEnrollment;
use App\Domains\Operations\Enums\AuditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithAgents;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class AgentEnrollmentTest extends TestCase
{
    use InteractsWithAgents, InteractsWithOrganizations, RefreshDatabase;

    public function test_issuing_an_enrollment_token_stores_it_hashed_and_marks_the_server_pending(): void
    {
        $owner = User::factory()->create();
        $organization = $this->organizationOwnedBy($owner);
        $server = Server::factory()->forOrganization($organization)->create();

        $response = $this->actingIn($organization, $owner)
            ->post(route('servers.enrollment', $server));

        $response->assertRedirect()->assertSessionHas('enrollment_token');

        $plaintext = $response->getSession()->get('enrollment_token');
        $enrollment = ServerEnrollment::query()->sole();

        $this->assertSame(ServerEnrollment::hashToken($plaintext), $enrollment->token_hash);
        $this->assertNotSame($plaintext, $enrollment->token_hash);
        $this->assertSame($server->id, $enrollment->server_id);
        $this->assertSame($organization->id, $enrollment->organization_id);
        $this->assertNull($enrollment->consumed_at);
        $this->assertTrue($enrollment->expires_at->between(now()->addMinutes(14), now()->addMinutes(16)));
        $this->assertSame(ServerStatus::InstallationPending, $server->refresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ServerEnrollmentIssued->value,
            'organization_id' => $organization->id,
            'actor_id' => $owner->id,
            'resource_id' => $server->id,
        ]);
    }

    public function test_agent_can_enroll_with_a_valid_token_and_receives_a_certificate(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $server = Server::factory()->forOrganization($organization)->create();

        ['enrollment' => $enrollment, 'token' => $token] = app(IssueEnrollmentToken::class)->execute($server);

        [, $csrPem] = $this->generateCsr($server->id);

        $response = $this->postJson(route('agent.enroll'), [
            'token' => $token,
            'csr' => $csrPem,
            'protocol_version' => '1.0',
            'agent_version' => '1.2.3',
            'hostname' => 'reported.example.com',
            'operating_system' => 'Ubuntu 24.04',
            'architecture' => 'x86_64',
            'docker_version' => '27.3.1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('protocol_version', '1.0')
            ->assertJsonPath('server.id', $server->id)
            ->assertJsonPath('heartbeat_interval_seconds', 30)
            ->assertJsonPath('command_poll_interval_seconds', 5)
            ->assertJsonStructure([
                'certificate' => ['pem', 'serial', 'fingerprint_sha256', 'expires_at'],
                'ca_certificate',
            ]);

        $certificate = ServerCertificate::query()->sole();

        $this->assertSame($server->id, $certificate->server_id);
        $this->assertSame($certificate->fingerprint_sha256, $response->json('certificate.fingerprint_sha256'));
        $this->assertStringContainsString('BEGIN CERTIFICATE', (string) $response->json('ca_certificate'));

        $parsed = openssl_x509_parse($certificate->certificate_pem);
        $this->assertSame($server->id, $parsed['subject']['CN']);
        $this->assertSame($certificate->fingerprint_sha256, $response->json('certificate.fingerprint_sha256'));

        $server->refresh();

        $this->assertSame(ServerStatus::AgentRegistered, $server->status);
        $this->assertSame('reported.example.com', $server->hostname);
        $this->assertSame('1.2.3', $server->agent_version);
        $this->assertSame('27.3.1', $server->docker_version);
        $this->assertNotNull($enrollment->refresh()->consumed_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ServerEnrolled->value,
            'organization_id' => $organization->id,
            'resource_id' => $server->id,
        ]);
    }

    public function test_expired_enrollment_tokens_are_rejected(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $server = Server::factory()->forOrganization($organization)->create();

        ['enrollment' => $enrollment, 'token' => $token] = app(IssueEnrollmentToken::class)->execute($server);

        $enrollment->forceFill(['expires_at' => now()->subMinute()])->save();

        [, $csrPem] = $this->generateCsr($server->id);

        $response = $this->postJson(route('agent.enroll'), [
            'token' => $token,
            'csr' => $csrPem,
            'agent_version' => '1.0.0',
            'hostname' => 'late.example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'enrollment_failed')
            ->assertJsonPath(
                'errors.token.0',
                'The enrollment token is invalid, expired, or has already been used.',
            );

        $this->assertSame(0, ServerCertificate::query()->count());
        $this->assertNull($enrollment->refresh()->consumed_at);
        $this->assertSame(ServerStatus::InstallationPending, $server->refresh()->status);
    }

    public function test_consumed_enrollment_tokens_cannot_be_reused(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $server = Server::factory()->forOrganization($organization)->create();

        ['token' => $token] = app(IssueEnrollmentToken::class)->execute($server);

        [, $csrPem] = $this->generateCsr($server->id);

        $payload = [
            'token' => $token,
            'csr' => $csrPem,
            'agent_version' => '1.0.0',
            'hostname' => 'first.example.com',
        ];

        $this->postJson(route('agent.enroll'), $payload)->assertCreated();

        [, $secondCsrPem] = $this->generateCsr($server->id);

        $this->postJson(route('agent.enroll'), [
            'token' => $token,
            'csr' => $secondCsrPem,
            'agent_version' => '1.0.0',
            'hostname' => 'replay.example.com',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'enrollment_failed')
            ->assertJsonPath(
                'errors.token.0',
                'The enrollment token is invalid, expired, or has already been used.',
            );

        $this->assertSame(1, ServerCertificate::query()->count());
        $this->assertSame('first.example.com', $server->refresh()->hostname);
    }

    public function test_enrollment_rejects_unknown_tokens(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $server = Server::factory()->forOrganization($organization)->create();

        [, $csrPem] = $this->generateCsr($server->id);

        $this->postJson(route('agent.enroll'), [
            'token' => 'not-a-real-token',
            'csr' => $csrPem,
            'agent_version' => '1.0.0',
            'hostname' => 'unknown.example.com',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'enrollment_failed')
            ->assertJsonPath(
                'errors.token.0',
                'The enrollment token is invalid, expired, or has already been used.',
            );

        $this->assertSame(0, ServerCertificate::query()->count());
    }

    public function test_enrollment_rejects_a_csr_for_a_different_server(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $server = Server::factory()->forOrganization($organization)->create();
        $otherServer = Server::factory()->forOrganization($organization)->create();

        ['enrollment' => $enrollment, 'token' => $token] = app(IssueEnrollmentToken::class)->execute($server);

        [, $csrPem] = $this->generateCsr($otherServer->id);

        $this->postJson(route('agent.enroll'), [
            'token' => $token,
            'csr' => $csrPem,
            'agent_version' => '1.0.0',
            'hostname' => 'impostor.example.com',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'enrollment_failed')
            ->assertJsonPath(
                'errors.csr.0',
                'The certificate signing request is invalid or does not match this server.',
            );

        $this->assertSame(0, ServerCertificate::query()->count());
        $this->assertNull($enrollment->refresh()->consumed_at);
        $this->assertSame(ServerStatus::InstallationPending, $server->refresh()->status);
    }

    public function test_enrollment_rejects_malformed_csrs(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $server = Server::factory()->forOrganization($organization)->create();

        ['token' => $token] = app(IssueEnrollmentToken::class)->execute($server);

        $this->postJson(route('agent.enroll'), [
            'token' => $token,
            'csr' => 'not a certificate signing request',
            'agent_version' => '1.0.0',
            'hostname' => 'garbage.example.com',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'enrollment_failed')
            ->assertJsonPath(
                'errors.csr.0',
                'The certificate signing request is invalid or does not match this server.',
            );

        $this->assertSame(0, ServerCertificate::query()->count());
    }

    public function test_issuing_a_new_token_invalidates_the_previous_one(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $server = Server::factory()->forOrganization($organization)->create();

        ['enrollment' => $first, 'token' => $firstToken] = app(IssueEnrollmentToken::class)->execute($server);
        ['token' => $secondToken] = app(IssueEnrollmentToken::class)->execute($server);

        $this->assertNotNull($first->refresh()->consumed_at);
        $this->assertSame(1, ServerEnrollment::query()->whereNull('consumed_at')->count());

        [, $csrPem] = $this->generateCsr($server->id);

        $this->postJson(route('agent.enroll'), [
            'token' => $firstToken,
            'csr' => $csrPem,
            'agent_version' => '1.0.0',
            'hostname' => 'stale.example.com',
        ])->assertStatus(422);

        $this->assertSame(0, ServerCertificate::query()->count());

        [, $secondCsrPem] = $this->generateCsr($server->id);

        $this->postJson(route('agent.enroll'), [
            'token' => $secondToken,
            'csr' => $secondCsrPem,
            'agent_version' => '1.0.0',
            'hostname' => 'current.example.com',
        ])->assertCreated();

        $this->assertSame(1, ServerCertificate::query()->count());
    }

    public function test_enrollment_tokens_cannot_be_issued_while_a_certificate_is_active(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $server = Server::factory()->forOrganization($organization)->create();

        $this->issueServerCertificate($server);

        try {
            app(IssueEnrollmentToken::class)->execute($server);
            $this->fail('An enrollment token was issued while a certificate was active.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'This server already has an active certificate. Revoke or rotate it instead.',
                $exception->errors()['server'][0] ?? null,
            );
        }

        $this->assertSame(0, ServerEnrollment::query()->count());
    }

    public function test_revoked_or_expired_certificates_allow_a_new_enrollment(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $server = Server::factory()->forOrganization($organization)->create();

        $certificate = $this->issueServerCertificate($server)['certificate'];
        $certificate->revoke('compromised');

        $result = app(IssueEnrollmentToken::class)->execute($server);

        $this->assertNotNull($result['enrollment']->refresh()->expires_at);
        $this->assertSame(1, ServerEnrollment::query()->count());
    }

    public function test_certificate_rotation_revokes_the_previous_certificate(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $server = Server::factory()->forOrganization($organization)->create();

        $first = $this->issueServerCertificate($server);

        [, $csrPem] = $this->generateCsr($server->id);

        $result = app(RotateServerCertificate::class)->execute($server, $csrPem);

        $this->assertNotSame($first['certificate']->id, $result['certificate']->id);
        $this->assertSame($server->id, $result['certificate']->server_id);
        $this->assertTrue($first['certificate']->refresh()->isRevoked());
        $this->assertSame('rotated', $first['certificate']->revocation_reason);
        $this->assertFalse($result['certificate']->isRevoked());
        $this->assertSame($result['certificate']->id, $server->activeCertificate()?->id);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $result['ca_certificate']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::ServerCertificateRotated->value,
            'organization_id' => $organization->id,
            'resource_id' => $server->id,
        ]);
    }

    public function test_rotation_rejects_a_csr_for_a_different_server(): void
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $server = Server::factory()->forOrganization($organization)->create();
        $otherServer = Server::factory()->forOrganization($organization)->create();

        $certificate = $this->issueServerCertificate($server)['certificate'];

        [, $csrPem] = $this->generateCsr($otherServer->id);

        try {
            app(RotateServerCertificate::class)->execute($server, $csrPem);
            $this->fail('A certificate was rotated with a CSR for another server.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'The certificate signing request is invalid or does not match this server.',
                $exception->errors()['csr'][0] ?? null,
            );
        }

        $this->assertSame(1, ServerCertificate::query()->count());
        $this->assertFalse($certificate->refresh()->isRevoked());
    }
}
