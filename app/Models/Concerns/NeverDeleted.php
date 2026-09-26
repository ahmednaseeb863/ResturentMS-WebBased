<?php

namespace App\Models\Concerns;

use App\Exceptions\PermanentDeleteNotAllowed;

/**
 * Money records that change state but are never removed or trashed (shifts, later orders
 * and payments): they are closed, voided, cancelled or reversed instead.
 */
trait NeverDeleted
{
    public static function bootNeverDeleted(): void
    {
        static::deleting(fn () => throw PermanentDeleteNotAllowed::neverDeleted(static::class));
    }

    public function delete(): never
    {
        throw PermanentDeleteNotAllowed::neverDeleted(static::class);
    }

    public function forceDelete(): never
    {
        throw PermanentDeleteNotAllowed::neverDeleted(static::class);
    }

    public static function destroy($ids): never
    {
        throw PermanentDeleteNotAllowed::neverDeleted(static::class);
    }
}
