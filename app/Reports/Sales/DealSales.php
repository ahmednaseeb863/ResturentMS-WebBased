<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportFilters;

/** Deals sold: how many and for how much. */
class DealSales extends Report
{
    public function key(): string
    {
        return 'deals';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Deal Sales';
    }

    public function description(): string
    {
        return 'Deals sold — quantity and sales per deal';
    }

    public function icon(): string
    {
        return 'badge-percent';
    }

    public function columns(): array
    {
        return [
            static::col('deal', 'Deal'),
            static::col('qty', 'Qty Sold', 'int', true),
            static::col('total', 'Sales', 'money', true),
            static::col('average', 'Avg Price', 'money'),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        return static::soldLines($f)
            ->where('order_items.sellable_type', 'deal')
            ->selectRaw('order_items.item_name as deal, sum(order_items.quantity) as qty, sum(order_items.line_total) as total')
            ->groupBy('order_items.item_name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'deal' => $r->deal,
                'qty' => (int) $r->qty,
                'total' => (float) $r->total,
                'average' => $r->qty ? round($r->total / $r->qty, 2) : 0,
            ])->all();
    }
}
