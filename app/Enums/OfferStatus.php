<?php

namespace App\Enums;

/** Where a deal / discount stands on a given day (from its dates and on/off switch). */
enum OfferStatus: string
{
    case Active = 'active';
    case Scheduled = 'scheduled';
    case Expired = 'expired';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Scheduled => 'Scheduled',
            self::Expired => 'Expired',
            self::Inactive => 'Switched off',
        };
    }

    /** StatusDot status on the lists. */
    public function dot(): string
    {
        return match ($this) {
            self::Active => 'active',
            self::Scheduled => 'pending',
            default => 'inactive',
        };
    }
}
