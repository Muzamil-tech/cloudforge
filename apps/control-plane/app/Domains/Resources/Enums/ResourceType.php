<?php

namespace App\Domains\Resources\Enums;

enum ResourceType: string
{
    case Postgresql = 'postgresql';
    case Redis = 'redis';

    public function label(): string
    {
        return match ($this) {
            self::Postgresql => 'PostgreSQL',
            self::Redis => 'Redis',
        };
    }

    public function image(): string
    {
        return match ($this) {
            self::Postgresql => 'postgres:16-alpine',
            self::Redis => 'redis:7-alpine',
        };
    }

    public function port(): int
    {
        return match ($this) {
            self::Postgresql => 5432,
            self::Redis => 6379,
        };
    }

    public function username(): ?string
    {
        return match ($this) {
            self::Postgresql => 'cloudforge',
            self::Redis => null,
        };
    }

    public function databaseName(): ?string
    {
        return match ($this) {
            self::Postgresql => 'cloudforge',
            self::Redis => null,
        };
    }

    public function scheme(): string
    {
        return match ($this) {
            self::Postgresql => 'postgresql',
            self::Redis => 'redis',
        };
    }

    /**
     * @return list<self>
     */
    public static function provisionable(): array
    {
        return self::cases();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
