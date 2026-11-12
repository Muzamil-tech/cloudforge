<?php

namespace App\Domains\Infrastructure\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'server_id',
    'serial',
    'fingerprint_sha256',
    'certificate_pem',
    'issued_at',
    'expires_at',
    'revoked_at',
    'revocation_reason',
])]
class ServerCertificate extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function publicKey(): \OpenSSLAsymmetricKey|false
    {
        return openssl_pkey_get_public($this->certificate_pem);
    }

    public function revoke(string $reason): void
    {
        $this->forceFill([
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ])->save();
    }
}
