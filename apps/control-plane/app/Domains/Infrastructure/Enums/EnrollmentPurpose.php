<?php

namespace App\Domains\Infrastructure\Enums;

enum EnrollmentPurpose: string
{
    case Installation = 'installation';
    case Bootstrap = 'bootstrap';

    public function isBootstrap(): bool
    {
        return $this === self::Bootstrap;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
