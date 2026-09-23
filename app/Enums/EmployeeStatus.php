<?php

namespace App\Enums;

/** Anything but Active switches the employee's login off. */
enum EmployeeStatus: string
{
    case Active = 'active';
    case OnLeave = 'on_leave';
    case Left = 'left';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::OnLeave => 'On leave',
            self::Left => 'Left',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
