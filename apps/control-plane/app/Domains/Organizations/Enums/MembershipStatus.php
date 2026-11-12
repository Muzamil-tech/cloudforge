<?php

namespace App\Domains\Organizations\Enums;

enum MembershipStatus: string
{
    case Active = 'active';
    case Invited = 'invited';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Invited => 'Invited',
            self::Suspended => 'Suspended',
        };
    }
}
