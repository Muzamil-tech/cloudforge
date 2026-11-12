<?php

namespace Tests\Concerns;

use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerCertificate;
use App\Domains\Infrastructure\Services\AgentCertificateAuthority;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;

trait InteractsWithAgents
{
    /**
     * Issue a certificate for a server directly through the CA, bypassing the
     * enrollment endpoint. Useful for exercising authenticated agent routes.
     *
     * @return array{certificate: ServerCertificate, key: OpenSSLAsymmetricKey}
     */
    protected function issueServerCertificate(Server $server): array
    {
        [$key, $csrPem] = $this->generateCsr($server->id);
        $issued = app(AgentCertificateAuthority::class)->signCsr($csrPem, $server->id);

        $certificate = ServerCertificate::query()->create([
            'server_id' => $server->id,
            'serial' => $issued->serial,
            'fingerprint_sha256' => $issued->fingerprint,
            'certificate_pem' => $issued->certificatePem,
            'issued_at' => now(),
            'expires_at' => $issued->expiresAt,
        ]);

        return ['certificate' => $certificate, 'key' => $key];
    }

    /**
     * @return array{0: OpenSSLAsymmetricKey, 1: string}
     */
    protected function generateCsr(string $commonName): array
    {
        $authority = app(AgentCertificateAuthority::class);
        $authority->ensureInitialized();
        $configuration = $authority->basePath().DIRECTORY_SEPARATOR.'openssl.cnf';

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => $configuration,
        ]);

        $this->assertNotFalse($key, 'Unable to generate a test private key.');

        $csr = openssl_csr_new(
            ['commonName' => $commonName, 'organizationName' => 'CloudForge Test'],
            $key,
            ['digest_alg' => 'sha256', 'config' => $configuration],
        );

        $this->assertNotFalse($csr, 'Unable to generate a test certificate signing request.');

        $this->assertTrue(openssl_csr_export($csr, $csrPem));

        return [$key, $csrPem];
    }

    /**
     * Perform a request signed with the CloudForge agent contract:
     *
     *   METHOD \n request-uri \n unix-timestamp \n nonce \n sha256(raw-body)
     *
     * @param  array<string, mixed>  $payload
     */
    protected function signedAgentRequest(
        ServerCertificate $certificate,
        OpenSSLAsymmetricKey $key,
        string $method,
        string $uri,
        array $payload = [],
        ?int $timestamp = null,
        ?string $nonce = null,
    ): TestResponse {
        $uri = $this->signedRequestUri($uri);
        $body = $payload === [] ? '' : json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp ??= now()->getTimestamp();
        $nonce ??= 'nonce-'.bin2hex(random_bytes(12));

        $canonical = implode("\n", [
            strtoupper($method),
            $uri,
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);

        $this->assertTrue(openssl_sign($canonical, $signature, $key, OPENSSL_ALGO_SHA256));

        return $this->call($method, $uri, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CLOUDFORGE_FINGERPRINT' => $certificate->fingerprint_sha256,
            'HTTP_X_CLOUDFORGE_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_CLOUDFORGE_NONCE' => $nonce,
            'HTTP_X_CLOUDFORGE_SIGNATURE' => base64_encode($signature),
        ], $body);
    }

    /**
     * Reduce an absolute URL to the request URI the verifier reads from the
     * server-side request (path plus optional query string).
     */
    protected function signedRequestUri(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return $uri;
        }

        $query = parse_url($uri, PHP_URL_QUERY);

        return is_string($query) && $query !== '' ? $path.'?'.$query : $path;
    }
}
