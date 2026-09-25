<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use App\Support\Activity;
use App\Support\TrashablePivot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Shared by all branches; `branches()` = where it can be used (bank transfers, expenses…). */
class BankAccount extends Model
{
    use HasFactory, HasPublicUuid, LogsActivity, Trashable;

    protected $fillable = ['bank_name', 'account_title', 'account_number', 'iban', 'is_active', 'show_on_receipt'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_on_receipt' => 'boolean',
        ];
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->using(BankAccountBranch::class)->wherePivotNull('deleted_at');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Accounts available at a branch (POS / expense pickers, receipts). */
    public function scopeAvailableAt(Builder $query, Branch|int $branch): void
    {
        $query->whereHas('branches', fn ($q) => $q->whereKey($branch instanceof Branch ? $branch->id : $branch));
    }

    /** Super admins see every account; others the accounts linked to one of their branches. */
    public function scopeVisibleTo(Builder $query, Admin $admin): void
    {
        if (! $admin->is_super_admin) {
            $query->whereHas('branches', fn ($q) => $q->whereIn('branches.id', $admin->accessibleBranches()->pluck('id')));
        }
    }

    /**
     * Set the branches this account is available at (removed links are trashed) and log it.
     *
     * @param  list<int>  $branchIds
     */
    public function syncBranches(array $branchIds): void
    {
        $changes = TrashablePivot::sync(BankAccountBranch::class, 'bank_account_id', $this->id, 'branch_id', $branchIds);

        if ($changes['attached'] || $changes['detached']) {
            $names = fn (array $ids) => Branch::withTrashed()->whereIn('id', $ids)->pluck('name')->all();
            Activity::log('branch_access', $this, ['granted' => $names($changes['attached']), 'removed' => $names($changes['detached'])]);
        }
    }

    public function trashLabel(): string
    {
        return "{$this->bank_name} — {$this->account_title}";
    }
}
