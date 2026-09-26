<?php

namespace App\Enums;

/** What a print job prints. Receipts, pre-bills and Z-reports join with billing. */
enum PrintDocument: string
{
    case Kot = 'kot';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Kot => 'Kitchen ticket',
            self::Void => 'Void slip',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
