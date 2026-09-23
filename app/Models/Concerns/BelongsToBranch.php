<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Per-branch data (CLAUDE.md §3). Fills `branch_id` from the current branch on
 * create and scopes every query to it. Escape hatches: `forBranch($branch)` and
 * `allBranches()` — only for super-admin reports / jobs, never for normal screens.
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope('branch', function (Builder $query) {
            $current = app(CurrentBranch::class);

            if ($current->id() !== null) {
                $query->where($query->qualifyColumn('branch_id'), $current->id());
            } elseif ($current->isEnforced()) {
                $query->whereRaw('1 = 0'); // signed in without a branch → nothing
            }
        });

        static::creating(function (Model $model) {
            if (empty($model->branch_id)) {
                $model->branch_id = app(CurrentBranch::class)->id()
                    ?? throw new LogicException('No current branch: cannot create '.class_basename($model).'.');
            }
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('branch_id')) {
                throw new LogicException('A record cannot be moved to another branch.');
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function scopeForBranch(Builder $query, Branch|int $branch): void
    {
        $query->withoutGlobalScope('branch')
            ->where($query->qualifyColumn('branch_id'), $branch instanceof Branch ? $branch->id : $branch);
    }

    public function scopeAllBranches(Builder $query): void
    {
        $query->withoutGlobalScope('branch');
    }
}
