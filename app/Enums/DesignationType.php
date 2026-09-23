<?php

namespace App\Enums;

/** What a designation does — pickers use it (POS waiter list, rider list, kitchen staff…). */
enum DesignationType: string
{
    case Manager = 'manager';
    case Cashier = 'cashier';
    case Waiter = 'waiter';
    case Kitchen = 'kitchen';
    case Rider = 'rider';
    case Storekeeper = 'storekeeper';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Kitchen => 'Kitchen staff',
            default => ucfirst($this->value),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
