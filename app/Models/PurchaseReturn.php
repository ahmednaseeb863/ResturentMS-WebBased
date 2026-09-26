<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasBranchNumber;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Goods sent back to the supplier from a purchase (stock out, the bill goes down). Append-only. */
class PurchaseReturn extends Model
{
    use AppendOnly, BelongsToBranch, HasBranchNumber, HasPublicUuid;

    protected const CODE_PREFIX = 'RET';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'total' => 'decimal:2'];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class)->withTrashed();
    }
}
