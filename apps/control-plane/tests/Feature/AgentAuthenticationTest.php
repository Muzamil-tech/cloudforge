<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSSLAsymmetricKey;
use Tests\Concerns\InteractsWithAgents;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class AgentAuthenticationTest extends TestCase
{
    use InteractsWithAgents, InteractsWithOrganizations, RefreshDatabase;

    private const AUTHENTICATION_FAILED = 'agent_authentication_failed';

    public function test_requests_without_signature_headers_are_rejected(): void
    {
        $this->getJson(route('agent.commands.next'))
            ->assertUnauthorized()
            ->assertJsonPath('code', self::AUTHENTICATION_FAILED)
            ->assertJsonPath('message', 'The agent signature headers are incomplete.');
    }

    public function test_requests_with_unknown_certificate_fingerprints_are_rejected(): void
    {
        [$certificate, $key] = $this->enrolledServer();

        $body = '';
        $timestamp = now()->getTimestamp();
        $nonce = 'unknown-fingerprint-nonce';

        $headers = $this->signedHeaders($certificate, $key, 'GET', route('agent.commands.next'), $body, $timestamp, $nonce);
        $headers['HTTP_X_CLOUDFORGE_FINGERPRINT'] = str_repeat('a', 64);

        $this->call('GET', route('agent.commands.next'), [], [], [], $headers, $body)
            ->assertUnauthorized()
            ->assertJsonPath('code', self::AUTHENTICATION_FAILED)
            ->assertJsonPath('message', 'The agent certificate is not active.');
    }

    public function test_stale_timestamps_are_rejected(): void
    {
        [$certificate, $key] = $this->enrolledServer();

        $this->signedAgentRequest(
            $certificate,
            $key,
            'GET',
            route('agent.commands.next'),
            timestamp: now()->subMinutes(10)->getTimestamp(),
        )
            ->assertUnauthorized()
            ->assertJsonPath('code', self::AUTHENTICATION_FAILED)
            ->assertJsonPath('message', 'The agent request timestamp is outside the allowed clock skew.');
    }

    public function test_timestamps_beyond_the_skew_limit_in_the_future_are_rejected(): void
    {
        [$certificate, $key] = $this->enrolledServer();

        $this->signedAgentRequest(
            $certificate,
            $key,
            'GET',
            route('agent.commands.next'),
            timestamp: now()->addMinutes(10)->getTimestamp(),
        )
            ->assertUnauthorized()
            ->assertJsonPath('message', 'The agent request timestamp is outside the allowed clock skew.');
    }

    public function test_malformed_nonces_are_rejected(): void
    {
        [$certificate, $key] = $this->enrolledServer();

        $this->signedAgentRequest(
            $certificate,
            $key,
            'GET',
            route('agent.commands.next'),
            nonce: 'too-short',
        )
            ->assertUnauthorized()
            ->assertJsonPath('message', 'The agent request nonce is invalid.');
    }

    public function test_nonces_cannot_be_replayed(): void
    {
        [$certificate, $key] = $this->enrolledServer();

        $nonce = 'replay-nonce-0123456789';

        $this->signedAgentRequest($certificate, $key, 'GET', route('agent.commands.next'), nonce: $nonce)
            ->assertNoContent();

        $this->signedAgentRequest($certificate, $key, 'GET', route('agent.commands.next'), nonce: $nonce)
            ->assertUnauthorized()
            ->assertJsonPath('code', self::AUTHENTICATION_FAILED)
            ->assertJsonPath('message', 'The agent request nonce has already been used.');
    }

    public function test_tampered_request_bodies_are_rejected(): void
    {
        [$certificate, $key] = $this->enrolledServer();

        $originalBody = json_encode(['agent_version' => '1.0.0', 'docker_available' => true], JSON_THROW_ON_ERROR);
        $tamperedBody = json_encode(['agent_version' => '9.9.9', 'docker_available' => false], JSON_THROW_ON_ERROR);

        $headers = $this->signedHeaders(
            $certificate,
            $key,
            'POST',
            route('agent.heartbeat'),
            $originalBody,
            now()->getTimestamp(),
            'tampered-body-nonce',
        );

        $this->call('POST', route('agent.heartbeat'), [], [], [], $headers, $tamperedBody)
            ->assertUnauthorized()
            ->assertJsonPath('code', self::AUTHENTICATION_FAILED)
            ->assertJsonPath('message', 'The agent request signature is invalid.');
    }

    public function test_revoked_certificates_are_rejected(): void
    {
        [$certificate, $key] = $this->enrolledServer();

        $certificate->revoke('compromised');

        $this->signedAgentRequest($certificate, $key, 'GET', route('agent.commands.next'))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'The agent certificate is not active.');
    }

    public function test_expired_certificates_are_rejected(): void
    {
        [$certificate, $key] = $this->enrolledServer();

        $certificate->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->signedAgentRequest($certificate, $key, 'GET', route('agent.commands.next'))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'The agent certificate is not active.');
    }

    public function test_valid_signed_requests_are_accepted(): void
    {
        [$certificate, $key] = $this->enrolledServer();

        $this->signedAgentRequest($certificate, $key, 'POST', route('agent.heartbeat'), [
            'agent_version' => '1.0.0',
            'docker_available' => true,
            'docker_version' => '27.3.1',
            'uptime_seconds' => 3600,
            'load_average' => [0.25, 0.5, 0.75],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('heartbeat_interval_seconds', 30);
    }

    public function test_trusted_terminator_header_is_required_when_enabled(): void
    {
        config(['cloudforge.agent.require_client_certificate_header' => true]);

        [$certificate, $key] = $this->enrolledServer();

        $body = '';
        $timestamp = now()->getTimestamp();

        $headers = $this->signedHeaders(
            $certificate,
            $key,
            'GET',
            route('agent.commands.next'),
            $body,
            $timestamp,
            'missing-terminator-nonce',
        );

        $this->call('GET', route('agent.commands.next'), [], [], [], $headers, $body)
            ->assertUnauthorized()
            ->assertJsonPath(
                'message',
                'The trusted TLS client certificate does not match the agent identity.',
            );
    }

    public function test_trusted_terminator_header_accepts_the_certificate_serial(): void
    {
        config(['cloudforge.agent.require_client_certificate_header' => true]);

        [$certificate, $key] = $this->enrolledServer();

        $body = '';
        $timestamp = now()->getTimestamp();

        $headers = $this->signedHeaders(
            $certificate,
            $key,
            'GET',
            route('agent.commands.next'),
            $body,
            $timestamp,
            'matching-terminator-nonce',
        );
        $headers['HTTP_X_CLIENT_CERT_SERIAL'] = $certificate->serial;

        $this->call('GET', route('agent.commands.next'), [], [], [], $headers, $body)
            ->assertNoContent();
    }

    public function test_trusted_terminator_header_accepts_the_certificate_fingerprint(): void
    {
        config(['cloudforge.agent.require_client_certificate_header' => true]);

        [$certificate, $key] = $this->enrolledServer();

        $body = '';
        $timestamp = now()->getTimestamp();

        $headers = $this->signedHeaders(
            $certificate,
            $key,
            'GET',
            route('agent.commands.next'),
            $body,
            $timestamp,
            'fingerprint-terminator-nonce',
        );
        $headers['HTTP_X_CLIENT_CERT_SERIAL'] = strtoupper($certificate->fingerprint_sha256);

        $this->call('GET', route('agent.commands.next'), [], [], [], $headers, $body)
            ->assertNoContent();
    }

    public function test_trusted_terminator_rejects_a_mismatched_serial(): void
    {
        config(['cloudforge.agent.require_client_certificate_header' => true]);

        [$certificate, $key] = $this->enrolledServer();

        $body = '';
        $timestamp = now()->getTimestamp();

        $headers = $this->signedHeaders(
            $certificate,
            $key,
            'GET',
            route('agent.commands.next'),
            $body,
            $timestamp,
            'mismatched-terminator-nonce',
        );
        $headers['HTTP_X_CLIENT_CERT_SERIAL'] = 'DEADBEEF';

        $this->call('GET', route('agent.commands.next'), [], [], [], $headers, $body)
            ->assertUnauthorized()
            ->assertJsonPath(
                'message',
                'The trusted TLS client certificate does not match the agent identity.',
            );
    }

    /**
     * @return array{0: ServerCertificate, 1: OpenSSLAsymmetricKey}
     */
    private function enrolledServer(): array
    {
        $organization = $this->organizationOwnedBy(User::factory()->create());
        $server = Server::factory()->forOrganization($organization)->ready()->create();

        $issued = $this->issueServerCertificate($server);

        return [$issued['certificate'], $issued['key']];
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(
        ServerCertificate $certificate,
        OpenSSLAsymmetricKey $key,
        string $method,
        string $uri,
        string $body,
        int $timestamp,
        string $nonce,
    ): array {
        $canonical = implode("\n", [
            strtoupper($method),
            $this->signedRequestUri($uri),
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);

        $this->assertTrue(openssl_sign($canonical, $signature, $key, OPENSSL_ALGO_SHA256));

        return [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CLOUDFORGE_FINGERPRINT' => $certificate->fingerprint_sha256,
            'HTTP_X_CLOUDFORGE_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_CLOUDFORGE_NONCE' => $nonce,
            'HTTP_X_CLOUDFORGE_SIGNATURE' => base64_encode($signature),
        ];
    }
}
