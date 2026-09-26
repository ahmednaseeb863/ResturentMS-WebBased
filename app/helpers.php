<?php

use App\Models\Branch;
use App\Support\Settings\SettingsResolver;

if (! function_exists('setting')) {
    /** Effective setting value: branch override → global → default. e.g. setting('tax.rate') */
    function setting(string $key, Branch|int|null $branch = null): mixed
    {
        return app(SettingsResolver::class)->get($key, $branch);
    }
}

if (! function_exists('money')) {
    /** "Rs 1,250" / "Rs 1,250.50" with the branch's currency symbol — for messages and print views. */
    function money(float|int|string|null $amount, Branch|int|null $branch = null): string
    {
        $amount = (float) $amount;
        $decimals = round($amount, 2) == round($amount) ? 0 : 2;
        $text = setting('general.currency_symbol', $branch).' '.number_format(abs($amount), $decimals);

        return $amount < 0 ? '−'.$text : $text;
    }
}
