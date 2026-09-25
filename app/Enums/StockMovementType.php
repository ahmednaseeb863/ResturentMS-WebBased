<?php

namespace App\Enums;

/** Why stock of a raw material / ready item went up or down (the stock ledger). */
enum StockMovementType: string
{
    case Opening = 'opening';
    case StockIn = 'stock_in';
    case Purchase = 'purchase';
    case Consumption = 'consumption';
    case Sale = 'sale';
    case SaleReturn = 'sale_return';
    case Waste = 'waste';
    case Adjustment = 'adjustment';
    case CountCorrection = 'count_correction';
    case PurchaseReturn = 'purchase_return';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening stock',
            self::StockIn => 'Stock added',
            self::Purchase => 'Purchase',
            self::Consumption => 'Kitchen use',
            self::Sale => 'Sale',
            self::SaleReturn => 'Sale return',
            self::Waste => 'Waste',
            self::Adjustment => 'Adjustment',
            self::CountCorrection => 'Count correction',
            self::PurchaseReturn => 'Purchase return',
        };
    }

    /** Tag colour on the ledger. */
    public function tone(): string
    {
        return match ($this) {
            self::Opening, self::StockIn, self::Purchase, self::SaleReturn => 'info',
            self::Waste, self::PurchaseReturn => 'warn',
            self::Consumption, self::Sale => 'accent',
            default => 'neutral',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
