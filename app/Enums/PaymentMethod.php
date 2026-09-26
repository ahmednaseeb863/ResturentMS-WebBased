<?php

namespace App\Enums;

/** How an order is paid (PLAN §4.13): cash into the shift's drawer, or a transfer into a bank account. */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank transfer',
        };
    }

    /** Switched on in the Payments settings of the branch. */
    public function enabled(?int $branchId = null): bool
    {
        return (bool) setting($this === self::Cash ? 'payments.cash' : 'payments.bank_transfer', $branchId);
    }

    public function tone(): string
    {
        return $this === self::Cash ? 'accent' : 'info';
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $m) => ['value' => $m->value, 'label' => $m->label()], self::cases());
    }
}
