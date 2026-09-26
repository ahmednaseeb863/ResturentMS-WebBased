<?php

namespace App\Enums;

/** Where an order was taken. */
enum OrderSource: string
{
    case Pos = 'pos';
    case WaiterApp = 'waiter_app';

    public function label(): string
    {
        return match ($this) {
            self::Pos => 'POS',
            self::WaiterApp => 'Waiter app',
        };
    }
}
