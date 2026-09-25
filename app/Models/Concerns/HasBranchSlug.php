<?php

namespace App\Models\Concerns;

use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * `slug` made from `name`, unique within the branch (trashed rows included).
 * Refreshed when the name changes.
 */
trait HasBranchSlug
{
    public static function bootHasBranchSlug(): void
    {
        static::saving(function (Model $model) {
            if ($model->slug && ! $model->isDirty('name')) {
                return;
            }

            $base = Str::slug($model->name) ?: 'item';
            $slug = $base;

            for ($i = 2; $model->slugTaken($slug); $i++) {
                $slug = "{$base}-{$i}";
            }

            $model->slug = $slug;
        });
    }

    protected function slugTaken(string $slug): bool
    {
        return static::query()->allBranches()->withTrashed()
            ->where('branch_id', $this->branch_id ?? app(CurrentBranch::class)->id())
            ->where('slug', $slug)
            ->when($this->exists, fn ($q) => $q->whereKeyNot($this->getKey()))
            ->exists();
    }
}
