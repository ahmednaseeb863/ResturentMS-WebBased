<?php

namespace App\Enums;

/** USB printers are reached by their OS printer name, network printers by IP + port. */
enum PrinterConnection: string
{
    case Usb = 'usb';
    case Network = 'network';

    public function label(): string
    {
        return match ($this) {
            self::Usb => 'USB',
            self::Network => 'Network (LAN)',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
