<?php

namespace App\Domains\Infrastructure\ValueObjects;

use Carbon\CarbonImmutable;

final readonly class IssuedCertificate
{
    public function __construct(
        public string $certificatePem,
        public string $serial,
        public string $fingerprint,
        public CarbonImmutable $expiresAt,
    ) {}
}
