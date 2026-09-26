<?php

namespace App\Enums;

/** A stock count: counting (draft) → submitted → approved (stock corrected), or cancelled. */
enum StockCountStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Counting',
            default => ucfirst($this->value),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'warn',
            self::Submitted => 'info',
            self::Approved => 'accent',
            self::Cancelled => 'neutral',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
