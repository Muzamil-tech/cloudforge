<?php

namespace App\Domains\Infrastructure\Enums;

enum ServerStatus: string
{
    case InstallationPending = 'installation_pending';
    case AgentRegistered = 'agent_registered';
    case HealthChecking = 'health_checking';
    case Ready = 'ready';
    case Active = 'active';
    case Draining = 'draining';
    case Maintenance = 'maintenance';
    case Unreachable = 'unreachable';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::InstallationPending => 'Installation pending',
            self::AgentRegistered => 'Agent registered',
            self::HealthChecking => 'Health checking',
            self::Ready => 'Ready',
            self::Active => 'Active',
            self::Draining => 'Draining',
            self::Maintenance => 'Maintenance',
            self::Unreachable => 'Unreachable',
            self::Error => 'Error',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active, self::Ready => 'success',
            self::InstallationPending, self::AgentRegistered, self::HealthChecking => 'info',
            self::Draining, self::Maintenance => 'warning',
            self::Unreachable, self::Error => 'danger',
        };
    }

    public function isReachable(): bool
    {
        return in_array($this, [self::Active, self::Ready, self::Draining, self::Maintenance], true);
    }

    public function acceptsScheduling(): bool
    {
        return $this === self::Active;
    }

    public function isOperationalLock(): bool
    {
        return in_array($this, [self::Draining, self::Maintenance], true);
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::InstallationPending => [self::AgentRegistered, self::Error, self::Unreachable],
            self::AgentRegistered => [self::HealthChecking, self::Ready, self::Active, self::Error, self::Unreachable],
            self::HealthChecking => [self::Ready, self::Active, self::Error, self::Unreachable],
            self::Ready => [self::Active, self::Draining, self::Maintenance, self::Unreachable, self::Error],
            self::Active => [self::Draining, self::Maintenance, self::Unreachable, self::Error],
            self::Draining => [self::Active, self::Maintenance, self::Unreachable, self::Error],
            self::Maintenance => [self::Active, self::Draining, self::Unreachable, self::Error],
            self::Unreachable => [self::Active, self::Ready, self::Draining, self::Maintenance, self::Error],
            self::Error => [self::AgentRegistered, self::Ready, self::Active, self::Maintenance],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return $this === $status || in_array($status, $this->allowedTransitions(), true);
    }
}
