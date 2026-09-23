<?php

namespace App\Models\Concerns;

use App\Exceptions\PermanentDeleteNotAllowed;
use App\Exceptions\TrashNotAllowed;
use App\Models\Admin;
use App\Support\Activity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Our own "soft delete" (CLAUDE.md §1, PLAN §7.1). Nothing is ever removed:
 *
 *   $model->trash($reason)       fills deleted_at/by/reason + trash_batch, cascades $trashCascade
 *   $model->restoreFromTrash()   brings it (and children from the same batch) back
 *   Model::withTrashed() / onlyTrashed() / withoutTrashed()
 *
 * delete(), forceDelete(), destroy() and bulk ->delete() throw PermanentDeleteNotAllowed.
 *
 * Optional on the model:
 *   protected array $trashCascade = ['items'];     child relations trashed/restored with it
 *   protected array $trashParents = ['category'];  refuse restore while a parent is trashed
 *   public function canBeTrashed(): true|string    return a reason to refuse
 *
 * Table needs `$table->trashable()`.
 */
trait Trashable
{
    public static function bootTrashable(): void
    {
        static::addGlobalScope(new TrashScope);
    }

    public function initializeTrashable(): void
    {
        $this->addObservableEvents(['trashing', 'trashed', 'restoring', 'restored']);
        $this->mergeCasts(['deleted_at' => 'datetime']);
    }

    public function newEloquentBuilder($query): TrashableBuilder
    {
        return new TrashableBuilder($query);
    }

    // ── Blocked hard deletes ──────────────────────────────────────────────

    public function delete(): never
    {
        throw PermanentDeleteNotAllowed::for(static::class);
    }

    public function forceDelete(): never
    {
        throw PermanentDeleteNotAllowed::for(static::class);
    }

    public static function destroy($ids): never
    {
        throw PermanentDeleteNotAllowed::for(static::class);
    }

    // ── State & rules ─────────────────────────────────────────────────────

    public function isTrashed(): bool
    {
        return $this->deleted_at !== null;
    }

    /** Override: return a human reason to refuse, or true. */
    public function canBeTrashed(): true|string
    {
        return true;
    }

    /** Override: return a human reason to refuse, or true. */
    public function canBeRestored(): true|string
    {
        return true;
    }

    /** Name shown in the Recycle Bin and activity log. */
    public function trashLabel(): string
    {
        return (string) ($this->name ?? $this->title ?? class_basename($this).' '.$this->getKey());
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'deleted_by')->withTrashed();
    }

    // ── Trash / restore ───────────────────────────────────────────────────

    public function trash(?string $reason = null): static
    {
        if ($this->isTrashed()) {
            return $this;
        }

        DB::transaction(fn () => $this->trashInBatch($reason, (string) Str::uuid7()));

        if ($this->logsTrashActivity()) {
            Activity::log('trashed', $this, $reason ? ['reason' => $reason] : []);
        }

        return $this;
    }

    public function restoreFromTrash(): static
    {
        if (! $this->isTrashed()) {
            return $this;
        }

        foreach ($this->trashRelations('trashParents') as $relation) {
            $parent = $this->{$relation}()->withTrashed()->first();

            if ($parent && $parent->isTrashed()) {
                throw new TrashNotAllowed(
                    'Restore the '.Str::headline(class_basename($parent))." “{$parent->trashLabel()}” first.",
                );
            }
        }

        $allowed = $this->canBeRestored();
        if ($allowed !== true) {
            throw new TrashNotAllowed($allowed);
        }

        DB::transaction(fn () => $this->restoreInBatch($this->trash_batch));

        if ($this->logsTrashActivity()) {
            Activity::log('restored', $this);
        }

        return $this;
    }

    /** @internal cascade step — use trash() */
    public function trashInBatch(?string $reason, string $batch): void
    {
        $allowed = $this->canBeTrashed();
        if ($allowed !== true) {
            throw new TrashNotAllowed($allowed);
        }

        if ($this->fireModelEvent('trashing') === false) {
            return;
        }

        $this->forceFill([
            'deleted_at' => now(),
            'deleted_by' => Auth::guard('admin')->id(),
            'delete_reason' => $reason,
            'trash_batch' => $batch,
        ])->save();

        foreach ($this->trashRelations('trashCascade') as $relation) {
            $this->{$relation}()->get()->each(fn ($child) => $child->trashInBatch($reason, $batch));
        }

        $this->fireModelEvent('trashed', false);
    }

    /** @internal cascade step — use restoreFromTrash() */
    public function restoreInBatch(?string $batch): void
    {
        if ($this->fireModelEvent('restoring') === false) {
            return;
        }

        $this->forceFill([
            'deleted_at' => null,
            'deleted_by' => null,
            'delete_reason' => null,
            'trash_batch' => null,
        ])->save();

        if ($batch !== null) {
            foreach ($this->trashRelations('trashCascade') as $relation) {
                $this->{$relation}()->onlyTrashed()->where('trash_batch', $batch)->get()
                    ->each(fn ($child) => $child->restoreInBatch($batch));
            }
        }

        $this->fireModelEvent('restored', false);
    }

    /** Pivot rows set `protected bool $logTrashActivity = false;` — the owner logs one summary instead. */
    protected function logsTrashActivity(): bool
    {
        return property_exists($this, 'logTrashActivity') ? $this->logTrashActivity : true;
    }

    /** @return list<string> */
    protected function trashRelations(string $property): array
    {
        return property_exists($this, $property) ? $this->{$property} : [];
    }

    /** Routes declared with ->withTrashed() (restore) can bind trashed records. */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        if (Route::current()?->allowsTrashedBindings()) {
            $query = $query->withTrashed(); // $query may be the model itself
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }
}
