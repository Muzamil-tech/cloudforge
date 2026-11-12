<?php

namespace App\Domains\Applications\Enums;

enum ApplicationStatus: string
{
    case Deploying = 'deploying';
    case Active = 'active';
    case Starting = 'starting';
    case Stopping = 'stopping';
    case Refreshing = 'refreshing';
    case Stopped = 'stopped';
    case Failed = 'failed';
    case Deleting = 'deleting';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Deploying => 'Deploying',
            self::Active => 'Active',
            self::Starting => 'Starting',
            self::Stopping => 'Stopping',
            self::Refreshing => 'Refreshing',
            self::Stopped => 'Stopped',
            self::Failed => 'Failed',
            self::Deleting => 'Deleting',
            self::Deleted => 'Deleted',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Deploying, self::Starting, self::Stopping, self::Refreshing, self::Deleting => 'info',
            self::Stopped => 'warning',
            self::Failed => 'danger',
            self::Deleted => 'neutral',
        };
    }

    public function isTransitional(): bool
    {
        return in_array($this, [
            self::Deploying,
            self::Starting,
            self::Stopping,
            self::Refreshing,
            self::Deleting,
        ], true);
    }

    public function canStart(): bool
    {
        return in_array($this, [self::Stopped, self::Failed], true);
    }

    public function canStop(): bool
    {
        return $this === self::Active;
    }

    public function canDeploy(): bool
    {
        return in_array($this, [self::Active, self::Stopped, self::Failed], true);
    }

    public function canDelete(): bool
    {
        return in_array($this, [self::Deploying, self::Active, self::Stopped, self::Failed], true);
    }

    public function canRefresh(): bool
    {
        return in_array($this, [self::Active, self::Stopped, self::Failed], true);
    }

    public function isDeleted(): bool
    {
        return $this === self::Deleted;
    }

    /**
     * @return list<self>
     */
    public static function transitional(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->isTransitional()));
    }
}
