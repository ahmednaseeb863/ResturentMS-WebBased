<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RawMaterial;
use App\Models\ReadyItem;
use App\Support\MenuOptions;
use App\Support\Qty;
use Illuminate\Database\Eloquent\Model;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Low stock (PLAN §4.16): active raw materials and ready items at or below their alert
 * level, most short first, with Add stock / Purchase shortcuts. Shown when the Inventory
 * setting "Low-stock alerts" is on.
 */
class LowStockController extends Controller
{
    public function index(): Response
    {
        $row = fn (Model $item, string $kind) => [
            'id' => $item->uuid,
            'kind' => $kind,
            'name' => $item->name,
            'code' => $item->code,
            'group' => $kind === 'raw_material' ? ($item->category?->name ?? 'Raw material') : 'Ready item',
            'stock_unit' => $item->stockUnit ? ['id' => $item->stockUnit->uuid, 'short_name' => $item->stockUnit->short_name] : null,
            'purchase_unit' => $item->purchaseUnit ? ['id' => $item->purchaseUnit->uuid, 'short_name' => $item->purchaseUnit->short_name] : null,
            'purchase_unit_factor' => $item->purchase_unit_factor,
            'current_stock' => $item->current_stock,
            'alert_level' => $item->alert_level,
            'avg_cost' => $item->avg_cost,
            'short' => round((float) $item->alert_level - (float) $item->current_stock, 3),
            'short_text' => Qty::format(max(0, (float) $item->alert_level - (float) $item->current_stock)).' '.$item->stockUnit?->short_name,
            'out' => (float) $item->current_stock <= 0,
        ];

        $items = RawMaterial::query()->active()->lowStock()->with('stockUnit', 'purchaseUnit', 'category')->get()->map(fn ($m) => $row($m, 'raw_material'))
            ->concat(ReadyItem::query()->active()->lowStock()->with('stockUnit', 'purchaseUnit')->get()->map(fn ($i) => $row($i, 'ready_item')))
            ->sortBy([['out', 'desc'], ['name', 'asc']])
            ->values();

        return Inertia::render('low-stock/Index', [
            'items' => $items,
            'enabled' => (bool) setting('inventory.low_stock_alerts'),
            'units' => MenuOptions::units(),
        ]);
    }

    /** Low-stock count for the sidebar badge (0 when alerts are off). */
    public static function count(): int
    {
        if (! setting('inventory.low_stock_alerts')) {
            return 0;
        }

        return RawMaterial::query()->active()->lowStock()->count() + ReadyItem::query()->active()->lowStock()->count();
    }
}
