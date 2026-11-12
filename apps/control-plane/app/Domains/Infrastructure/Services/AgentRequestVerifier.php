<?php

namespace App\Domains\Infrastructure\Services;

use App\Domains\Infrastructure\Models\ServerCertificate;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Verifies the CloudForge agent request signature:
 *
 *   METHOD \n request-uri \n unix-timestamp \n nonce \n sha256(raw-body)
 *
 * The certificate fingerprint selects the registered public key. Signatures
 * are RSA-SHA256 and every nonce is accepted once per certificate.
 */
class AgentRequestVerifier
{
    public const FINGERPRINT_HEADER = 'X-CloudForge-Fingerprint';

    public const TIMESTAMP_HEADER = 'X-CloudForge-Timestamp';

    public const NONCE_HEADER = 'X-CloudForge-Nonce';

    public const SIGNATURE_HEADER = 'X-CloudForge-Signature';

    public function verify(Request $request): ServerCertificate
    {
        $fingerprint = AgentCertificateAuthority::normalizeFingerprint(
            (string) $request->header(self::FINGERPRINT_HEADER),
        );

        $timestamp = (string) $request->header(self::TIMESTAMP_HEADER);
        $nonce = (string) $request->header(self::NONCE_HEADER);
        $signature = base64_decode((string) $request->header(self::SIGNATURE_HEADER), true);

        if ($fingerprint === '' || $timestamp === '' || $nonce === '' || $signature === false || $signature === '') {
            throw new AuthenticationException('The agent signature headers are incomplete.');
        }

        if (! ctype_digit($timestamp)) {
            throw new AuthenticationException('The agent request timestamp is invalid.');
        }

        $skew = (int) config('cloudforge.agent.request_clock_skew_seconds', 300);

        if (abs(now()->getTimestamp() - (int) $timestamp) > $skew) {
            throw new AuthenticationException('The agent request timestamp is outside the allowed clock skew.');
        }

        if (preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce) !== 1) {
            throw new AuthenticationException('The agent request nonce is invalid.');
        }

        $certificate = ServerCertificate::query()
            ->with('server.organization')
            ->where('fingerprint_sha256', $fingerprint)
            ->first();

        if ($certificate === null || ! $certificate->isActive() || $certificate->server === null) {
            throw new AuthenticationException('The agent certificate is not active.');
        }

        $this->ensureTrustedTerminatorMatches($request, $certificate);

        $replayKey = sprintf('cloudforge:agent:nonce:%s:%s', $fingerprint, $nonce);

        if (! Cache::add($replayKey, true, now()->addSeconds($skew * 2))) {
            throw new AuthenticationException('The agent request nonce has already been used.');
        }

        $canonical = implode("\n", [
            $request->getMethod(),
            $request->getRequestUri(),
            $timestamp,
            $nonce,
            hash('sha256', $request->getContent()),
        ]);

        $publicKey = $certificate->publicKey();

        if ($publicKey === false || openssl_verify($canonical, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new AuthenticationException('The agent request signature is invalid.');
        }

        return $certificate;
    }

    private function ensureTrustedTerminatorMatches(Request $request, ServerCertificate $certificate): void
    {
        if (! (bool) config('cloudforge.agent.require_client_certificate_header', false)) {
            return;
        }

        $header = (string) config('cloudforge.agent.client_certificate_serial_header', 'X-Client-Cert-Serial');
        $presented = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->header($header)) ?? '');
        $serial = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $certificate->serial) ?? '');
        $fingerprint = strtoupper($certificate->fingerprint_sha256);

        if ($presented === '' || ($presented !== $serial && $presented !== $fingerprint)) {
            throw new AuthenticationException('The trusted TLS client certificate does not match the agent identity.');
        }
    }
}
