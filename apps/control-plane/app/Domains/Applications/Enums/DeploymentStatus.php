<?php

namespace App\Domains\Applications\Enums;

enum DeploymentStatus: string
{
    case Deploying = 'deploying';
    case Active = 'active';
    case Failed = 'failed';
    case Superseded = 'superseded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Deploying => 'Deploying',
            self::Active => 'Active',
            self::Failed => 'Failed',
            self::Superseded => 'Superseded',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Deploying => 'info',
            self::Failed => 'danger',
            self::Superseded, self::Cancelled => 'neutral',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Active, self::Failed, self::Superseded, self::Cancelled], true);
    }
}
