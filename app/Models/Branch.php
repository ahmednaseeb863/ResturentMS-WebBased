<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Branch extends Model
{
    use HasFactory, HasPublicUuid, LogsActivity, Trashable;

    protected $fillable = ['code', 'name', 'address', 'phone', 'email', 'tax_number', 'manager_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(Admin::class)->using(AdminBranch::class)->wherePivotNull('deleted_at');
    }

    /** Allocated manager (an employee, usually of this branch). */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id')->allBranches()->withTrashed();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function canBeTrashed(): true|string
    {
        if (static::query()->active()->whereKeyNot($this->getKey())->doesntExist()) {
            return 'This is the only active branch — add or activate another branch first.';
        }

        return true;
    }
}
