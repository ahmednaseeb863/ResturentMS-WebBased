<?php

namespace App\Models\Concerns;

use App\Exceptions\PermanentDeleteNotAllowed;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent builder for Trashable models: trash scopes, bulk trash/restore,
 * and hard deletes blocked at the query level too.
 */
class TrashableBuilder extends Builder
{
    public function withTrashed(bool $withTrashed = true): static
    {
        return $withTrashed ? $this->withoutGlobalScope(TrashScope::class) : $this->withoutTrashed();
    }

    public function withoutTrashed(): static
    {
        return $this->withoutGlobalScope(TrashScope::class)->whereNull($this->qualifyColumn('deleted_at'));
    }

    public function onlyTrashed(): static
    {
        return $this->withoutGlobalScope(TrashScope::class)->whereNotNull($this->qualifyColumn('deleted_at'));
    }

    /** Bulk trash — each row goes through the model (rules, cascade, activity log). */
    public function trash(?string $reason = null): int
    {
        $models = $this->get();
        $models->each->trash($reason);

        return $models->count();
    }

    /** Bulk restore — use on `onlyTrashed()`. */
    public function restoreFromTrash(): int
    {
        $models = $this->withoutGlobalScope(TrashScope::class)->get();
        $models->each->restoreFromTrash();

        return $models->count();
    }

    public function delete(): mixed
    {
        throw PermanentDeleteNotAllowed::for($this->model::class);
    }

    public function forceDelete(): mixed
    {
        throw PermanentDeleteNotAllowed::for($this->model::class);
    }
}
