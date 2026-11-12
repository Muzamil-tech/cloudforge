<?php

namespace App\Domains\Projects\Enums;

enum EnvironmentType: string
{
    case Development = 'development';
    case Staging = 'staging';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Development => 'Development',
            self::Staging => 'Staging',
            self::Production => 'Production',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
