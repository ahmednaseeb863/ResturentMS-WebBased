<?php

namespace App\Models;

use App\Enums\DesignationType;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Job title shared by every branch (Manager, Cashier, Waiter…). */
class Designation extends Model
{
    use HasFactory, HasPublicUuid, LogsActivity, Trashable;

    protected $fillable = ['name', 'type', 'default_role_id', 'is_active'];

    protected function casts(): array
    {
        return [
            'type' => DesignationType::class,
            'is_active' => 'boolean',
        ];
    }

    public function defaultRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'default_role_id');
    }

    /** Employees of the current branch (BelongsToBranch scope). */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function canBeTrashed(): true|string
    {
        $count = $this->employees()->allBranches()->count();

        return $count > 0
            ? "{$count} employee(s) have this designation — change their designation first."
            : true;
    }
}
