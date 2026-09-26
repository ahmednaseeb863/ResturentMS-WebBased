<?php

namespace App\Enums;

/** How much of the bill is paid; after refunds: part refunded / refunded (nothing kept). */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';
    case PartRefunded = 'part_refunded';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PartRefunded => 'Part refunded',
            default => ucfirst($this->value),
        };
    }

    /** Tag colour. */
    public function tone(): string
    {
        return match ($this) {
            self::Paid => 'accent',
            self::Partial, self::PartRefunded => 'warn',
            self::Refunded => 'danger',
            default => 'neutral',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
