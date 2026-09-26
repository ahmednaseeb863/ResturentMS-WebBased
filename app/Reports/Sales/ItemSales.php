<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportFilters;

/** Menu items and ready items sold (not voided): quantity and sales. */
class ItemSales extends Report
{
    public function key(): string
    {
        return 'items';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Item Sales';
    }

    public function description(): string
    {
        return 'Menu and ready items sold — quantity, sales and share';
    }

    public function icon(): string
    {
        return 'utensils';
    }

    public function columns(): array
    {
        return [
            static::col('item', 'Item'),
            static::col('kind', 'Type'),
            static::col('qty', 'Qty Sold', 'int', true),
            static::col('total', 'Sales', 'money', true),
            static::col('average', 'Avg Price', 'money'),
            static::col('share', 'Share', 'percent'),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        $rows = static::soldLines($f)
            ->whereIn('order_items.sellable_type', ['menu_item', 'ready_item'])
            ->selectRaw('order_items.item_name as item, order_items.variant_name as variant, order_items.sellable_type as kind, sum(order_items.quantity) as qty, sum(order_items.line_total) as total')
            ->groupBy('order_items.item_name', 'order_items.variant_name', 'order_items.sellable_type')
            ->orderByDesc('total')
            ->get();
        $all = (float) $rows->sum('total');

        return $rows->map(fn ($r) => [
            'item' => $r->item.($r->variant ? " ({$r->variant})" : ''),
            'kind' => $r->kind === 'ready_item' ? 'Ready item' : 'Menu item',
            'qty' => (int) $r->qty,
            'total' => (float) $r->total,
            'average' => $r->qty ? round($r->total / $r->qty, 2) : 0,
            'share' => static::pct((float) $r->total, $all),
        ])->all();
    }
}
