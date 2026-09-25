<?php

namespace App\Support;

/** Quantity text for messages and logs: 1.500 → "1.5", 150.000 → "150". */
class Qty
{
    public static function format(float|int|string|null $value): string
    {
        $text = number_format((float) $value, 3, '.', '');

        return rtrim(rtrim($text, '0'), '.') ?: '0';
    }

    /** Unit factors: 0.001000 → "0.001", 12.000000 → "12". */
    public static function formatPrecise(float|int|string|null $value): string
    {
        $text = number_format((float) $value, 6, '.', '');

        return rtrim(rtrim($text, '0'), '.') ?: '0';
    }
}
