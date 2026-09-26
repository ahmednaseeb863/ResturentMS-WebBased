<?php

namespace App\Reports\Inventory;

use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** In and out per stock item in the period, by kind of movement. */
class StockMovementSummary extends Report
{
    public function key(): string
    {
        return 'stock-movements';
    }

    public function group(): string
    {
        return 'inventory';
    }

    public function title(): string
    {
        return 'Stock Movement Summary';
    }

    public function description(): string
    {
        return 'Per item — purchased, sold, used in the kitchen, wasted, corrected';
    }

    public function icon(): string
    {
        return 'history';
    }

    public function landscape(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            static::col('item', 'Item'),
            static::col('unit', 'Unit'),
            static::col('in', 'Stock In', 'qty'),
            static::col('returned', 'Returned to Supplier', 'qty'),
            static::col('sold', 'Sold', 'qty'),
            static::col('used', 'Kitchen Use', 'qty'),
            static::col('wasted', 'Waste', 'qty'),
            static::col('corrected', 'Corrections', 'qty'),
            static::col('net', 'Net Change', 'qty'),
            static::col('cost_out', 'Cost Out', 'money', true),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        $sum = fn (string $types) => "sum(case when sm.type in ({$types}) then sm.quantity else 0 end)";

        return static::costJoins(static::inDates(static::inBranches(DB::table('stock_movements as sm'), $f, 'sm.branch_id'), $f, 'sm.business_date'))
            ->leftJoin('units as u', 'u.id', '=', DB::raw('coalesce(rm.stock_unit_id, ri.stock_unit_id)'))
            ->selectRaw('coalesce(rm.name, ri.name) as name, u.short_name as unit, '
                .$sum("'opening','stock_in','purchase','sale_return'").' as qin, '
                .$sum("'purchase_return'").' as qret, '
                .$sum("'sale'").' as qsold, '
                .$sum("'consumption'").' as qused, '
                .$sum("'waste'").' as qwaste, '
                .$sum("'adjustment','count_correction'").' as qcorr, '
                .'sum(sm.quantity) as qnet, '
                .'sum(case when sm.quantity < 0 then '.static::movementCost().' else 0 end) as cost_out')
            ->groupBy('sm.stockable_type', 'sm.stockable_id', 'rm.name', 'ri.name', 'u.short_name')
            ->orderBy('name')
            ->get()
            ->map(fn ($r) => [
                'item' => $r->name,
                'unit' => $r->unit,
                'in' => round((float) $r->qin, 3),
                'returned' => round(-(float) $r->qret, 3),
                'sold' => round(-(float) $r->qsold, 3),
                'used' => round(-(float) $r->qused, 3),
                'wasted' => round(-(float) $r->qwaste, 3),
                'corrected' => round((float) $r->qcorr, 3),
                'net' => round((float) $r->qnet, 3),
                'cost_out' => round((float) $r->cost_out, 2),
            ])->all();
    }
}
