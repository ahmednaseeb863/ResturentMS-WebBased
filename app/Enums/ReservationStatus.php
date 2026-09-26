<?php

namespace App\Enums;

/** A table booking (PLAN §4.17): pending → confirmed → seated, or cancelled / no-show. */
enum ReservationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Seated = 'seated';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::NoShow => 'No-show',
            default => ucfirst($this->value),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warn',
            self::Confirmed => 'info',
            self::Seated => 'accent',
            self::Cancelled, self::NoShow => 'neutral',
        };
    }

    /** Still coming: holds its table, can be edited. */
    public function isUpcoming(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed], true);
    }

    /** @return list<self> */
    public static function upcoming(): array
    {
        return [self::Pending, self::Confirmed];
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
