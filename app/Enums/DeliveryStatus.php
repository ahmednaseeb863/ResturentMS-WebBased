<?php

namespace App\Enums;

/**
 * PLAN §4.14: pending → assigned → out for delivery → delivered / failed → returned.
 * Failed = the rider could not deliver (still has the food); returned = back at the shop,
 * can be assigned again or the order cancelled.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Assigned = 'assigned';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Unassigned',
            self::OutForDelivery => 'Out for delivery',
            default => ucfirst($this->value),
        };
    }

    /** Tag colour. */
    public function tone(): string
    {
        return match ($this) {
            self::Pending, self::Failed => 'warn',
            self::Assigned, self::OutForDelivery => 'info',
            self::Delivered => 'accent',
            default => 'neutral',
        };
    }

    /** Still to finish (on the board / rider panel). */
    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Assigned, self::OutForDelivery, self::Failed], true);
    }

    /** A rider may be (re)assigned. */
    public function canAssign(): bool
    {
        return in_array($this, [self::Pending, self::Assigned, self::Returned], true);
    }

    /** The rider has the food — the order can't be changed or cancelled. */
    public function isDispatched(): bool
    {
        return in_array($this, [self::OutForDelivery, self::Failed, self::Delivered], true);
    }

    /** @return list<self> */
    public static function active(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isActive()));
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
