<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
 * Project rule (CLAUDE.md §2): numeric ids never leave the server.
 * Fails if any `id` is an integer or any `*_id` key is present in Inertia props.
 */
function expectNoNumericIds(array $props, string $path = 'props'): void
{
    foreach ($props as $key => $value) {
        $here = "{$path}.{$key}";

        if ($key === 'id') {
            expect($value)->not->toBeInt("{$here} is a numeric id");
        }

        if (is_string($key) && str_ends_with($key, '_id')) {
            test()->fail("{$here}: foreign keys must not be sent to the frontend");
        }

        if (is_array($value)) {
            expectNoNumericIds($value, $here);
        }
    }
}
