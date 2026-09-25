<?php

namespace App\Models\Concerns;

use App\Models\StockMovement;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use InvalidArgumentException;

/**
 * Raw materials and ready items: stock kept in `stock_unit`, changed only through the
 * ledger (App\Support\StockLedger). `purchase_unit_factor` = stock units in one purchase
 * unit (carton of 24 pcs → 24).
 */
trait Stockable
{
    public function initializeStockable(): void
    {
        $this->mergeCasts([
            'current_stock' => 'decimal:3',
            'alert_level' => 'decimal:3',
            'purchase_unit_factor' => 'decimal:3',
            'avg_cost' => 'decimal:4',
        ]);
    }

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'stock_unit_id')->withTrashed();
    }

    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'purchase_unit_id')->withTrashed();
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'stockable');
    }

    /** 2 cartons → 48 pcs; 500 g → 0.5 kg. Needs stockUnit / purchaseUnit loaded. */
    public function toStockQuantity(float $quantity, Unit $unit): float
    {
        $stockUnit = $this->stockUnit;

        if ($unit->sameFamily($stockUnit)) {
            return Unit::convert($quantity, $unit, $stockUnit);
        }

        if ($this->purchase_unit_id === $unit->id && $this->purchase_unit_factor > 0) {
            return $quantity * (float) $this->purchase_unit_factor;
        }

        throw new InvalidArgumentException("{$this->name} is not counted in {$unit->short_name}.");
    }

    /** Can a quantity in this unit be entered for the item? */
    public function acceptsUnit(Unit $unit): bool
    {
        return $unit->sameFamily($this->stockUnit) || ($this->purchase_unit_id === $unit->id && $this->purchase_unit_factor > 0);
    }

    public function isLowStock(): bool
    {
        return $this->alert_level !== null && (float) $this->current_stock <= (float) $this->alert_level;
    }

    public function scopeLowStock(Builder $query): void
    {
        $query->whereNotNull('alert_level')->whereColumn('current_stock', '<=', 'alert_level');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Stock value at average cost. */
    public function stockValue(): float
    {
        return round((float) $this->current_stock * (float) $this->avg_cost, 2);
    }
}
