<?php

namespace App\Domains\Organizations\Enums;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Developer = 'developer';
    case Operator = 'operator';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Developer => 'Developer',
            self::Operator => 'Operator',
            self::Viewer => 'Viewer',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 50,
            self::Admin => 40,
            self::Developer => 30,
            self::Operator => 20,
            self::Viewer => 10,
        };
    }

    public function canManageOrganization(): bool
    {
        return $this === self::Owner;
    }

    public function canManageMembers(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canManageInfrastructure(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canDeploy(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Developer], true);
    }

    public function canOperate(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Operator], true);
    }

    public function canView(): bool
    {
        return true;
    }

    /**
     * Roles that may be assigned by this role. Owners cannot be created by
     * anyone other than an owner, and only an owner can promote to owner.
     *
     * @return list<self>
     */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::Owner => [self::Admin, self::Developer, self::Operator, self::Viewer, self::Owner],
            self::Admin => [self::Developer, self::Operator, self::Viewer],
            default => [],
        };
    }

    public static function fromRank(int $rank): self
    {
        return match (true) {
            $rank >= 50 => self::Owner,
            $rank >= 40 => self::Admin,
            $rank >= 30 => self::Developer,
            $rank >= 20 => self::Operator,
            default => self::Viewer,
        };
    }
}
