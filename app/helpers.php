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
