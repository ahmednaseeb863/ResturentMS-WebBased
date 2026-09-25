<?php

namespace App\Enums;

/** How a table is drawn on the floor plan, and how many grid cells it covers. */
enum TableShape: string
{
    case Square = 'square';
    case Round = 'round';
    case Rectangle = 'rectangle';
    case Long = 'long';

    public function label(): string
    {
        return match ($this) {
            self::Square => 'Square',
            self::Round => 'Round',
            self::Rectangle => 'Rectangle',
            self::Long => 'Long (family)',
        };
    }

    /** @return array{0: int, 1: int} width, height in floor-plan cells */
    public function size(): array
    {
        return match ($this) {
            self::Square, self::Round => [2, 2],
            self::Rectangle => [3, 2],
            self::Long => [5, 2],
        };
    }

    /** @return list<array{value: string, label: string, w: int, h: int}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label(), 'w' => $s->size()[0], 'h' => $s->size()[1]], self::cases());
    }
}
