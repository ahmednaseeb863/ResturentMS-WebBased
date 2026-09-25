<?php

namespace App\Enums;

/** Receipt printers sit on cash counters; kitchen printers print KOTs for a kitchen station. */
enum PrinterType: string
{
    case Receipt = 'receipt';
    case Kitchen = 'kitchen';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Receipt',
            self::Kitchen => 'Kitchen (KOT)',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
