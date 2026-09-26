<?php

namespace App\Reports\Inventory;

use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** Raw materials used by the kitchen: expected (recipe) vs actual. */
class ConsumptionVariance extends Report
{
    public function key(): string
    {
        return 'consumption';
    }

    public function group(): string
    {
        return 'inventory';
    }

    public function title(): string
    {
        return 'Consumption: Expected vs Actual';
    }

    public function description(): string
    {
        return 'Raw materials used by the kitchen against the recipes — variance per item';
    }

    public function icon(): string
    {
        return 'scale';
    }

    public function columns(): array
    {
        return [
            static::col('material', 'Raw Material'),
            static::col('unit', 'Unit'),
            static::col('expected', 'Expected', 'qty'),
            static::col('actual', 'Actual', 'qty'),
            static::col('variance', 'Variance', 'qty'),
            static::col('variance_pct', 'Variance %', 'percent'),
            static::col('lines', 'Lines', 'int', true),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        return static::inDates(static::inBranches(DB::table('order_item_consumptions as c'), $f, 'c.branch_id'), $f, 'c.business_date')
            ->join('raw_materials as m', 'm.id', '=', 'c.raw_material_id')
            ->join('units as u', 'u.id', '=', 'c.unit_id')
            ->selectRaw('m.name, u.short_name as unit, sum(c.expected_qty) as expected, sum(c.actual_qty) as actual, count(distinct c.order_item_id) as line_count')
            ->groupBy('m.id', 'm.name', 'u.id', 'u.short_name')
            ->orderBy('m.name')
            ->get()
            ->map(fn ($r) => [
                'material' => $r->name,
                'unit' => $r->unit,
                'expected' => round((float) $r->expected, 3),
                'actual' => round((float) $r->actual, 3),
                'variance' => round((float) $r->actual - (float) $r->expected, 3),
                'variance_pct' => static::pct((float) $r->actual - (float) $r->expected, (float) $r->expected),
                'lines' => (int) $r->line_count,
            ])->all();
    }
}
