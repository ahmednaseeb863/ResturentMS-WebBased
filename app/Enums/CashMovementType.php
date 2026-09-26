<?php

namespace App\Enums;

/**
 * Cash going in / out of a drawer other than sales and refunds (PLAN §6 "Expected cash").
 * The amount is stored positive; `direction()` gives the sign.
 */
enum CashMovementType: string
{
    case CashIn = 'cash_in';
    case CashOut = 'cash_out';
    case SafeDrop = 'safe_drop';
    case RiderSettlement = 'rider_settlement';
    case Expense = 'expense';
    case SupplierPayment = 'supplier_payment';

    public function label(): string
    {
        return match ($this) {
            self::CashIn => 'Cash in',
            self::CashOut => 'Cash out',
            self::SafeDrop => 'Safe drop',
            self::RiderSettlement => 'Rider settlement',
            self::Expense => 'Paid-out expense',
            self::SupplierPayment => 'Supplier payment',
        };
    }

    /** +1 adds to the drawer, -1 takes out of it. */
    public function direction(): int
    {
        return match ($this) {
            self::CashIn, self::RiderSettlement => 1,
            default => -1,
        };
    }

    /** Entered by hand on the shift screen; the others come from riders / expenses / purchases. */
    public function isManual(): bool
    {
        return in_array($this, [self::CashIn, self::CashOut, self::SafeDrop], true);
    }

    /** A reason is required (a safe drop explains itself). */
    public function needsReason(): bool
    {
        return in_array($this, [self::CashIn, self::CashOut], true);
    }

    /** Tag colour. */
    public function tone(): string
    {
        return match ($this) {
            self::CashIn, self::RiderSettlement => 'info',
            self::SafeDrop => 'accent',
            default => 'warn',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function manualOptions(): array
    {
        return array_values(array_map(
            fn (self $t) => ['value' => $t->value, 'label' => $t->label()],
            array_filter(self::cases(), fn (self $t) => $t->isManual()),
        ));
    }
}
