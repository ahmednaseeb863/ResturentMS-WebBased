<?php

namespace App\Enums;

/** PLAN §4.14: pending → assigned → out for delivery → delivered / failed / returned. */
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
            self::OutForDelivery => 'Out for delivery',
            default => ucfirst($this->value),
        };
    }
}
