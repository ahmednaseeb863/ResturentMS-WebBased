<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportFilters;

/** Sales per menu category (menu and ready items; deals on their own line). */
class CategorySales extends Report
{
    public function key(): string
    {
        return 'categories';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Category Sales';
    }

    public function description(): string
    {
        return 'Sales per menu category, deals on their own line';
    }

    public function icon(): string
    {
        return 'folder-tree';
    }

    public function chart(): ?array
    {
        return ['label' => 'category', 'value' => 'total'];
    }

    public function columns(): array
    {
        return [
            static::col('category', 'Category'),
            static::col('qty', 'Qty Sold', 'int', true),
            static::col('total', 'Sales', 'money', true),
            static::col('share', 'Share', 'percent'),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        $by = fn (string $type, string $table) => static::soldLines($f)
            ->where('order_items.sellable_type', $type)
            ->join("{$table} as s", 's.id', '=', 'order_items.sellable_id')
            ->leftJoin('categories as c', 'c.id', '=', 's.category_id')
            ->selectRaw('coalesce(c.name, ?) as category, sum(order_items.quantity) as qty, sum(order_items.line_total) as total', ['No category'])
            ->groupBy('c.name')
            ->get();

        $deals = static::soldLines($f)->where('order_items.sellable_type', 'deal')
            ->selectRaw("'Deals' as category, sum(order_items.quantity) as qty, sum(order_items.line_total) as total")
            ->get()
            ->filter(fn ($r) => $r->qty > 0);

        $rows = $by('menu_item', 'menu_items')->concat($by('ready_item', 'ready_items'))->concat($deals)
            ->groupBy('category')
            ->map(fn ($g, $name) => ['category' => $name, 'qty' => (int) $g->sum('qty'), 'total' => round((float) $g->sum('total'), 2)])
            ->sortByDesc('total')
            ->values();
        $all = (float) $rows->sum('total');

        return $rows->map(fn ($r) => [...$r, 'share' => static::pct($r['total'], $all)])->all();
    }
}
