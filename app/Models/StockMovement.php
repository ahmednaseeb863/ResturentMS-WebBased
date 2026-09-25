<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\TrashScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of the stock ledger (append-only). `quantity` is signed and in the item's
 * stock unit; `balance_after` is the stock right after it. Written only by StockLedger.
 */
class StockMovement extends Model
{
    use AppendOnly, BelongsToBranch, HasPublicUuid;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'business_date' => 'date',
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'balance_after' => 'decimal:3',
        ];
    }

    public function stockable(): MorphTo
    {
        // MorphTo::withTrashed() only knows SoftDeletes; drop our trash scope instead
        // (withoutGlobalScopes is replayed on every morph type when eager loading)
        return $this->morphTo()->withoutGlobalScopes([TrashScope::class]);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class)->withTrashed();
    }
}
