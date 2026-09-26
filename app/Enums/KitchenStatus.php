<?php

namespace App\Enums;

/** Kitchen progress of an order line / kitchen ticket. */
enum KitchenStatus: string
{
    case Pending = 'pending';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Served = 'served';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'neutral',
            self::Preparing => 'warn',
            self::Ready => 'info',
            self::Served => 'accent',
        };
    }
}
