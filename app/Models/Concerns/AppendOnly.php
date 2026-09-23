<?php

namespace App\Models\Concerns;

use App\Exceptions\PermanentDeleteNotAllowed;

/**
 * Ledgers & history (activity log, stock movements, status histories…): rows are
 * only ever inserted. Mistakes are fixed with a new correcting entry.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn () => throw PermanentDeleteNotAllowed::appendOnly(static::class));
    }

    public function delete(): never
    {
        throw PermanentDeleteNotAllowed::appendOnly(static::class);
    }

    public function forceDelete(): never
    {
        throw PermanentDeleteNotAllowed::appendOnly(static::class);
    }

    public static function destroy($ids): never
    {
        throw PermanentDeleteNotAllowed::appendOnly(static::class);
    }
}
