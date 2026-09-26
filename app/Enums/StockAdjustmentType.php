<?php

namespace App\Enums;

/** Stock written off (PLAN §4.16): spoiled / expired, or broken / damaged. */
enum StockAdjustmentType: string
{
    case Waste = 'waste';
    case Damage = 'damage';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
