<?php

namespace App\Exceptions;

use LogicException;

/**
 * Thrown by every hard-delete path on a Trashable / append-only model.
 * Nothing is ever removed from the database â€” use `trash($reason)` instead.
 */
class PermanentDeleteNotAllowed extends LogicException
{
    public static function for(string $model): self
    {
        return new self("Permanent delete is not allowed on [{$model}]. Use trash() instead.");
    }

    public static function appendOnly(string $model): self
    {
        return new self("[{$model}] is append-only: records cannot be changed or deleted.");
    }

    public static function neverDeleted(string $model): self
    {
        return new self("[{$model}] records are never deleted or trashed — close, void or reverse them instead.");
    }
}
