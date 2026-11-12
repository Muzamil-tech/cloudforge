<?php

namespace App\Domains\Infrastructure\Services;

use App\Domains\Infrastructure\ValueObjects\IssuedCertificate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Issues short-lived client certificates for enrolled servers from a private
 * local certificate authority. The CA key never leaves the control plane
 * storage volume.
 */
class AgentCertificateAuthority
{
    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim($basePath ?? (string) config('cloudforge.agent.ca_path'), DIRECTORY_SEPARATOR);
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function ensureInitialized(): void
    {
        if ($this->isInitialized()) {
            return;
        }

        File::ensureDirectoryExists($this->basePath, 0750);

        $lock = fopen($this->path('ca.lock'), 'c');

        if ($lock === false) {
            throw new RuntimeException('Unable to open the agent CA lock file.');
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to lock the agent CA for initialization.');
            }

            if ($this->isInitialized()) {
                return;
            }

            $this->generateCertificateAuthority();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function certificatePem(): string
    {
        $this->ensureInitialized();

        $pem = File::get($this->path('ca.crt.pem'));

        if ($pem === '') {
            throw new RuntimeException('The agent CA certificate is empty.');
        }

        return $pem;
    }

    public function signCsr(string $csrPem, string $expectedCommonName, ?int $ttlDays = null): IssuedCertificate
    {
        $this->ensureInitialized();

        $csr = openssl_csr_get_subject($csrPem);

        if ($csr === false) {
            throw new RuntimeException('The certificate signing request could not be parsed.');
        }

        $commonName = $csr['CN'] ?? null;

        if (! is_string($commonName) || ! hash_equals($expectedCommonName, $commonName)) {
            throw new RuntimeException('The certificate signing request subject does not match the server.');
        }

        if (openssl_csr_get_public_key($csrPem) === false) {
            throw new RuntimeException('The certificate signing request has no usable public key.');
        }

        $ttlDays ??= (int) config('cloudforge.agent.certificate_ttl_days', 365);
        $serial = random_int(1, PHP_INT_MAX);

        $certificate = openssl_csr_sign(
            $csrPem,
            File::get($this->path('ca.crt.pem')),
            File::get($this->path('ca.key.pem')),
            $ttlDays,
            [
                'digest_alg' => 'sha256',
                'x509_extensions' => 'v3_agent',
                'config' => $this->path('openssl.cnf'),
            ],
            $serial,
        );

        if ($certificate === false) {
            throw new RuntimeException('The certificate signing request could not be signed.');
        }

        openssl_x509_export($certificate, $certificatePem);

        if ($certificatePem === '') {
            throw new RuntimeException('The issued certificate could not be exported.');
        }

        $parsed = openssl_x509_parse($certificate);

        if ($parsed === false) {
            throw new RuntimeException('The issued certificate could not be parsed.');
        }

        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');

        if ($fingerprint === false) {
            throw new RuntimeException('The issued certificate fingerprint could not be computed.');
        }

        return new IssuedCertificate(
            certificatePem: $certificatePem,
            serial: $this->normalizeSerial($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? (string) $serial),
            fingerprint: $this->normalizeFingerprint($fingerprint),
            expiresAt: CarbonImmutable::createFromTimestampUTC((int) $parsed['validTo_time_t']),
        );
    }

    public static function normalizeFingerprint(string $fingerprint): string
    {
        return strtolower(str_replace([':', ' '], '', trim($fingerprint)));
    }

    private function isInitialized(): bool
    {
        return File::exists($this->path('ca.key.pem'))
            && File::exists($this->path('ca.crt.pem'));
    }

    private function generateCertificateAuthority(): void
    {
        $this->writeOpenSslConfiguration();

        $key = openssl_pkey_new([
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => $this->path('openssl.cnf'),
        ]);

        if ($key === false) {
            throw new RuntimeException('Unable to generate the agent CA private key.');
        }

        if (! openssl_pkey_export($key, $privateKeyPem, null, ['config' => $this->path('openssl.cnf')])) {
            throw new RuntimeException('Unable to export the agent CA private key.');
        }

        $csr = openssl_csr_new(
            [
                'commonName' => (string) config('cloudforge.agent.ca_common_name', 'CloudForge Agent CA'),
                'organizationName' => (string) config('cloudforge.name', 'CloudForge'),
            ],
            $key,
            [
                'digest_alg' => 'sha256',
                'config' => $this->path('openssl.cnf'),
            ],
        );

        if ($csr === false) {
            throw new RuntimeException('Unable to create the agent CA certificate request.');
        }

        $certificate = openssl_csr_sign(
            $csr,
            null,
            $key,
            3650,
            [
                'digest_alg' => 'sha256',
                'x509_extensions' => 'v3_ca',
                'config' => $this->path('openssl.cnf'),
            ],
            random_int(1, PHP_INT_MAX),
        );

        if ($certificate === false) {
            throw new RuntimeException('Unable to sign the agent CA certificate.');
        }

        openssl_x509_export($certificate, $certificatePem);

        $this->atomicWrite('ca.key.pem', $privateKeyPem, 0600);
        $this->atomicWrite('ca.crt.pem', $certificatePem, 0644);
    }

    private function writeOpenSslConfiguration(): void
    {
        $configuration = <<<'CNF'
[req]
distinguished_name = dn
prompt = no

[dn]
CN = CloudForge Agent CA
O = CloudForge

[v3_ca]
basicConstraints = critical, CA:TRUE, pathlen:0
keyUsage = critical, keyCertSign, cRLSign, digitalSignature
subjectKeyIdentifier = hash

[v3_agent]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = clientAuth
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer
CNF;

        $this->atomicWrite('openssl.cnf', $configuration, 0644);
    }

    private function atomicWrite(string $filename, string $contents, int $mode): void
    {
        $temporary = $this->path($filename.'.tmp');

        if (File::put($temporary, $contents) === false) {
            throw new RuntimeException("Unable to write {$filename}.");
        }

        @chmod($temporary, $mode);

        if (! rename($temporary, $this->path($filename))) {
            throw new RuntimeException("Unable to publish {$filename}.");
        }

        @chmod($this->path($filename), $mode);
    }

    private function path(string $filename): string
    {
        return $this->basePath.DIRECTORY_SEPARATOR.$filename;
    }

    private function normalizeSerial(string $serial): string
    {
        $serial = strtoupper(str_replace([':', ' '], '', trim($serial)));

        if ($serial === '') {
            throw new RuntimeException('The issued certificate serial number is empty.');
        }

        return $serial;
    }
}
