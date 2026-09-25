<?php

namespace App\Enums;

/** Live state of a dining table. `occupied` is set by orders only. */
enum TableStatus: string
{
    case Available = 'available';
    case Occupied = 'occupied';
    case Reserved = 'reserved';
    case Cleaning = 'cleaning';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Occupied => 'Occupied',
            self::Reserved => 'Reserved',
            self::Cleaning => 'Cleaning',
        };
    }

    /** Statuses staff may set by hand (occupied comes from an open order). */
    public function isManual(): bool
    {
        return $this !== self::Occupied;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
