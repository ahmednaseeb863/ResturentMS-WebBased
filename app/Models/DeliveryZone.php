<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Delivery zone of a branch (PLAN §4.14), e.g. "Gulberg — Rs 150, min Rs 800". Used when
 * the Delivery setting "Use delivery zones" is on: the zone's fee and minimum replace the
 * defaults. The fee is copied onto the order, so changing a zone never changes old bills.
 */
class DeliveryZone extends Model
{
    use BelongsToBranch, HasFactory, HasPublicUuid, LogsActivity, Trashable;

    protected $fillable = ['name', 'fee', 'min_order_amount', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /** The zone's minimum, else the Delivery setting's (0 = none). */
    public function minimum(): float
    {
        return $this->min_order_amount !== null ? (float) $this->min_order_amount : (float) setting('delivery.min_order_amount', $this->branch_id);
    }
}
