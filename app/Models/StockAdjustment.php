<?php

namespace App\Models;

use App\Enums\StockAdjustmentType;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasBranchNumber;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Waste / damage written off (RecordWaste), valued at the average cost. Append-only. */
class StockAdjustment extends Model
{
    use AppendOnly, BelongsToBranch, HasBranchNumber, HasPublicUuid;

    protected const CODE_PREFIX = 'W';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => StockAdjustmentType::class, 'business_date' => 'date', 'total_cost' => 'decimal:2'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class)->orderBy('id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class)->withTrashed();
    }
}
