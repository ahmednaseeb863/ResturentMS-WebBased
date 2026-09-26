<?php

namespace App\Models;

use App\Enums\StockCountStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasBranchNumber;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\NeverDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical stock count (PLAN §4.16): the system quantity is noted when the count starts,
 * staff enter what they counted and submit, a manager approves — the differences become
 * count corrections in the ledger. Cancelled instead of deleted.
 */
class StockCount extends Model
{
    use BelongsToBranch, HasBranchNumber, HasPublicUuid, NeverDeleted;

    protected const CODE_PREFIX = 'CNT';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => StockCountStatus::class,
            'business_date' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'variance_value' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class)->orderBy('item_name');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by')->withTrashed();
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'submitted_by')->withTrashed();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by')->withTrashed();
    }
}
