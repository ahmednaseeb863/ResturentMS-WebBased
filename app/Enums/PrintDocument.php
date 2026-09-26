<?php

namespace App\Enums;

/** What a print job prints. Z-reports may join later. */
enum PrintDocument: string
{
    case Kot = 'kot';
    case Void = 'void';
    case PreBill = 'pre_bill';
    case Receipt = 'receipt';

    public function label(): string
    {
        return match ($this) {
            self::Kot => 'Kitchen ticket',
            self::Void => 'Void slip',
            self::PreBill => 'Bill (pre-bill)',
            self::Receipt => 'Receipt',
        };
    }

    /** Printed in the kitchen (KitchenSlip); the others at a cash counter (BillSlip). */
    public function isKitchen(): bool
    {
        return in_array($this, [self::Kot, self::Void], true);
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
