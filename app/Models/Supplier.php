<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier (PLAN §4.16), shared by every branch. What a branch owes it is worked out
 * from that branch's purchases, returns and payments (`balanceIn`).
 */
class Supplier extends Model
{
    use HasFactory, HasPublicUuid, LogsActivity, Trashable;

    protected $fillable = ['name', 'contact_person', 'phone', 'email', 'address', 'ntn', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Not while any branch still owes it money (or has paid in advance). */
    public function canBeTrashed(): true|string
    {
        $billed = (float) $this->purchases()->withoutGlobalScope('branch')->toBase()->selectRaw('coalesce(sum(total - returned_total), 0) as owed')->value('owed');
        $paid = (float) $this->payments()->withoutGlobalScope('branch')->sum('amount');
        $open = round($billed - $paid, 2);

        return abs($open) < 0.01 ? true : 'Its account is not settled ('.money(abs($open)).($open > 0 ? ' owed' : ' paid in advance').').';
    }

    /** Owed to the supplier by a branch: bills − returns − payments (negative = paid in advance). */
    public function balanceIn(int $branchId): float
    {
        $billed = (float) $this->purchases()->withoutGlobalScope('branch')->where('branch_id', $branchId)
            ->toBase()->selectRaw('coalesce(sum(total - returned_total), 0) as owed')->value('owed');
        $paid = (float) $this->payments()->withoutGlobalScope('branch')->where('branch_id', $branchId)->sum('amount');

        return round($billed - $paid, 2);
    }
}
