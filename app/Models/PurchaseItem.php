<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\NeverDeleted;
use App\Models\Concerns\TrashScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A line of a purchase: raw material or ready item, quantity in any unit it is bought in. */
class PurchaseItem extends Model
{
    use HasPublicUuid, NeverDeleted;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'stock_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'line_total' => 'decimal:2',
            'returned_stock_quantity' => 'decimal:3',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function stockable(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes([TrashScope::class]);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }

    /** Stock units that can still be sent back. */
    public function returnable(): float
    {
        return max(0, round((float) $this->stock_quantity - (float) $this->returned_stock_quantity, 3));
    }

    /** Cost of one stock unit on this line. */
    public function stockUnitCost(): float
    {
        return (float) $this->stock_quantity > 0 ? (float) $this->line_total / (float) $this->stock_quantity : 0.0;
    }
}
