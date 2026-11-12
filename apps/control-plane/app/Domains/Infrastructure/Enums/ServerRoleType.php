<?php

namespace App\Domains\Infrastructure\Enums;

enum ServerRoleType: string
{
    case Control = 'control';
    case Application = 'application';
    case Database = 'database';
    case Build = 'build';
    case Edge = 'edge';
    case Storage = 'storage';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Control => 'Control plane',
            self::Application => 'Application',
            self::Database => 'Database',
            self::Build => 'Build',
            self::Edge => 'Edge',
            self::Storage => 'Storage',
            self::General => 'General',
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
