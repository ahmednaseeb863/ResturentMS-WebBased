<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Model;

/**
 * Stock columns shared by raw material and ready item resources. Load `stockUnit`,
 * `purchaseUnit` and `withExists('stockMovements')`.
 */
class StockFields
{
    public static function of(Model $item): array
    {
        return [
            'stock_unit' => $item->relationLoaded('stockUnit') && $item->stockUnit ? Resource::refOf($item->stockUnit, ['name', 'short_name']) : null,
            'purchase_unit' => $item->relationLoaded('purchaseUnit') && $item->purchaseUnit ? Resource::refOf($item->purchaseUnit, ['name', 'short_name']) : null,
            'purchase_unit_factor' => $item->purchase_unit_factor,
            'current_stock' => $item->current_stock,
            'alert_level' => $item->alert_level,
            'avg_cost' => $item->avg_cost,
            'stock_value' => $item->stockValue(),
            'is_low' => $item->isLowStock(),
            'has_movements' => (bool) ($item->stock_movements_exists ?? false),
        ];
    }
}
