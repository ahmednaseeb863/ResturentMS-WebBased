<?php

namespace App\Support;

use App\Models\RawMaterial;
use App\Models\ReadyItem;
use Illuminate\Database\Eloquent\Model;

/**
 * Raw materials and ready items of the current branch for the stock line pickers
 * (purchases, waste, stock counts). `key` = "raw_material:uuid".
 */
class StockOptions
{
    /** @return list<array<string, mixed>> */
    public static function items(bool $activeOnly = true): array
    {
        $map = fn (Model $item, string $kind) => [
            'key' => "{$kind}:{$item->uuid}",
            'kind' => $kind,
            'id' => $item->uuid,
            'name' => $item->name,
            'code' => $item->code,
            'group' => $kind === 'raw_material' ? ($item->category?->name ?? 'Raw materials') : 'Ready items',
            'stock_unit' => $item->stockUnit?->uuid,
            'stock_unit_name' => $item->stockUnit?->short_name,
            'purchase_unit' => $item->purchaseUnit?->uuid,
            'purchase_unit_factor' => $item->purchase_unit_factor === null ? null : (float) $item->purchase_unit_factor,
            'current_stock' => (float) $item->current_stock,
            'avg_cost' => (float) $item->avg_cost,
            'alert_level' => $item->alert_level === null ? null : (float) $item->alert_level,
        ];

        $raw = RawMaterial::query()->with('stockUnit', 'purchaseUnit', 'category')
            ->when($activeOnly, fn ($q) => $q->active())->orderBy('name')->get()
            ->map(fn (RawMaterial $m) => $map($m, 'raw_material'));
        $ready = ReadyItem::query()->with('stockUnit', 'purchaseUnit')
            ->when($activeOnly, fn ($q) => $q->active())->orderBy('name')->get()
            ->map(fn (ReadyItem $i) => $map($i, 'ready_item'));

        return $raw->concat($ready)->values()->all();
    }
}
