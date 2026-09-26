<?php

namespace App\Enums;

/**
 * Order lifecycle (PLAN §5): draft (held cart) → placed (sent) → preparing → ready →
 * served (dine-in) / out for delivery → delivered → completed (paid); or cancelled.
 */
enum OrderStatus: string
{
    case Draft = 'draft';
    case Placed = 'placed';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Served = 'served';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Held',
            self::OutForDelivery => 'Out for delivery',
            default => ucfirst($this->value),
        };
    }

    /** Tag colour. */
    public function tone(): string
    {
        return match ($this) {
            self::Placed, self::Preparing => 'warn',
            self::Ready, self::Served, self::OutForDelivery, self::Delivered => 'info',
            self::Completed => 'accent',
            default => 'neutral',
        };
    }

    /** Still being worked on (holds its table, can take items / be cancelled). */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Completed, self::Cancelled, self::Refunded], true);
    }

    /** @return list<self> */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isOpen()));
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
