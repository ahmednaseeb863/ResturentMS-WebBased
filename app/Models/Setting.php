<?php

namespace App\Models;

use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stored setting value: `branch_id` NULL = global, else a branch override.
 * Never read this directly — use App\Support\Settings\SettingsResolver (`setting('tax.rate')`).
 * Changes are logged per group by SaveSettings, so the row itself is not logged.
 */
class Setting extends Model
{
    use Trashable;

    protected bool $logTrashActivity = false;

    protected $fillable = ['branch_id', 'group', 'key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeGlobal(Builder $query): void
    {
        $query->whereNull('branch_id');
    }

    public function scopeOfBranch(Builder $query, Branch|int $branch): void
    {
        $query->where('branch_id', $branch instanceof Branch ? $branch->id : $branch);
    }
}
