<?php

namespace App\Reports\Finance;

use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/**
 * Cost and profit per item sold: sales against the raw materials the kitchen confirmed for
 * those lines (ready items: their average cost). Lines still waiting for confirmation have no cost yet.
 */
class MenuItemProfit extends Report
{
    public function key(): string
    {
        return 'item-profit';
    }

    public function group(): string
    {
        return 'finance';
    }

    public function title(): string
    {
        return 'Cost & Profit per Item';
    }

    public function description(): string
    {
        return 'Sales vs cost of each item sold — profit and food cost %';
    }

    public function icon(): string
    {
        return 'utensils-crossed';
    }

    public function columns(): array
    {
        return [
            static::col('item', 'Item'),
            static::col('qty', 'Qty Sold', 'int', true),
            static::col('sales', 'Sales', 'money', true),
            static::col('cost', 'Cost', 'money', true),
            static::col('profit', 'Profit', 'money', true),
            static::col('cost_pct', 'Food Cost %', 'percent'),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        $lines = static::soldLines($f)
            ->leftJoin('ready_items as ri', fn ($j) => $j->on('ri.id', '=', 'order_items.sellable_id')->where('order_items.sellable_type', 'ready_item'))
            ->select('order_items.id', 'order_items.item_name', 'order_items.variant_name', 'order_items.quantity', 'order_items.line_total', DB::raw('coalesce(ri.avg_cost, 0) * order_items.quantity as ready_cost'))
            ->get();

        $costs = collect();
        foreach ($lines->pluck('id')->chunk(1000) as $ids) {
            $costs = $costs->union(
                static::costJoins(DB::table('order_item_consumptions as c')
                    ->join('stock_movements as sm', 'sm.id', '=', 'c.stock_movement_id')
                    ->join('order_items as x', 'x.id', '=', 'c.order_item_id'))
                    ->whereIn(DB::raw('coalesce(x.parent_order_item_id, x.id)'), $ids->all())
                    ->selectRaw('coalesce(x.parent_order_item_id, x.id) as top, sum('.static::movementCost().') as cost')
                    ->groupBy('top')
                    ->pluck('cost', 'top')
            );
        }

        return $lines->groupBy(fn ($l) => $l->item_name.($l->variant_name ? " ({$l->variant_name})" : ''))
            ->map(function ($group, $name) use ($costs) {
                $sales = (float) $group->sum('line_total');
                $cost = round($group->sum(fn ($l) => (float) ($costs[$l->id] ?? 0) + (float) $l->ready_cost), 2);

                return [
                    'item' => $name,
                    'qty' => (int) $group->sum('quantity'),
                    'sales' => round($sales, 2),
                    'cost' => $cost,
                    'profit' => round($sales - $cost, 2),
                    'cost_pct' => static::pct($cost, $sales),
                ];
            })
            ->sortByDesc('profit')
            ->values()
            ->all();
    }

    public function totals(array $rows): ?array
    {
        $totals = parent::totals($rows);
        if ($totals) {
            $totals['cost_pct'] = static::pct($totals['cost'], $totals['sales']);
        }

        return $totals;
    }
}
