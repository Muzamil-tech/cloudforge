<?php

namespace App\Domains\Operations\Enums;

enum AgentCommandStatus: string
{
    case Pending = 'pending';
    case Acknowledged = 'acknowledged';
    case Executing = 'executing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Acknowledged => 'Acknowledged',
            self::Executing => 'Executing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Pending, self::Acknowledged, self::Executing => 'info',
            self::Expired => 'warning',
            self::Failed, self::Cancelled => 'danger',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Expired, self::Cancelled], true);
    }

    public function canTransitionTo(self $status): bool
    {
        if ($this->isTerminal()) {
            return false;
        }

        return match ($this) {
            self::Pending => in_array($status, [self::Acknowledged, self::Executing, self::Completed, self::Failed, self::Cancelled, self::Expired], true),
            self::Acknowledged => in_array($status, [self::Executing, self::Completed, self::Failed, self::Cancelled, self::Expired], true),
            self::Executing => in_array($status, [self::Completed, self::Failed, self::Cancelled], true),
            default => false,
        };
    }
}
