<?php

namespace App\Enums;

/** A discount is taken off the whole order or off one cart line. */
enum DiscountScope: string
{
    case Order = 'order';
    case Item = 'item';

    public function label(): string
    {
        return match ($this) {
            self::Order => 'Whole order',
            self::Item => 'One item',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
