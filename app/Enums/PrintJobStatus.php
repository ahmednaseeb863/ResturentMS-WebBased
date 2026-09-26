<?php

namespace App\Enums;

/** pending → printing (a device claimed it) → printed / failed (retry puts it back to pending). */
enum PrintJobStatus: string
{
    case Pending = 'pending';
    case Printing = 'printing';
    case Printed = 'printed';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending, self::Printing => 'warn',
            self::Printed => 'info',
            self::Failed => 'danger',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
