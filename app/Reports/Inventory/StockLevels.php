<?php

namespace App\Reports\Inventory;

use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** Stock on hand now and its value at average cost. */
class StockLevels extends Report
{
    public function key(): string
    {
        return 'stock-levels';
    }

    public function group(): string
    {
        return 'inventory';
    }

    public function title(): string
    {
        return 'Stock Levels';
    }

    public function description(): string
    {
        return 'Stock on hand now, value at average cost, low / out of stock';
    }

    public function icon(): string
    {
        return 'package';
    }

    public function usesDates(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [
            static::col('item', 'Item'),
            static::col('kind', 'Type'),
            static::col('category', 'Category'),
            static::col('stock', 'In Stock', 'qty'),
            static::col('unit', 'Unit'),
            static::col('avg_cost', 'Avg Cost', 'money'),
            static::col('value', 'Value', 'money', true),
            static::col('state', 'Status'),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        $raw = static::inBranches(DB::table('raw_materials as i'), $f, 'i.branch_id')
            ->whereNull('i.deleted_at')
            ->leftJoin('raw_material_categories as c', 'c.id', '=', 'i.category_id')
            ->leftJoin('units as u', 'u.id', '=', 'i.stock_unit_id')
            ->leftJoin('branches as br', 'br.id', '=', 'i.branch_id')
            ->selectRaw("i.name, 'Raw material' as kind, c.name as category, i.current_stock, i.avg_cost, i.alert_level, u.short_name as unit, br.name as branch")
            ->get();
        $ready = static::inBranches(DB::table('ready_items as i'), $f, 'i.branch_id')
            ->whereNull('i.deleted_at')
            ->leftJoin('categories as c', 'c.id', '=', 'i.category_id')
            ->leftJoin('units as u', 'u.id', '=', 'i.stock_unit_id')
            ->leftJoin('branches as br', 'br.id', '=', 'i.branch_id')
            ->selectRaw("i.name, 'Ready item' as kind, c.name as category, i.current_stock, i.avg_cost, i.alert_level, u.short_name as unit, br.name as branch")
            ->get();

        return $raw->concat($ready)->sortBy('name')->map(fn ($r) => [
            'item' => $r->name.($f->isMultiBranch() ? " · {$r->branch}" : ''),
            'kind' => $r->kind,
            'category' => $r->category,
            'stock' => (float) $r->current_stock,
            'unit' => $r->unit,
            'avg_cost' => (float) $r->avg_cost,
            'value' => round((float) $r->current_stock * (float) $r->avg_cost, 2),
            'state' => match (true) {
                (float) $r->current_stock <= 0 => 'Out',
                $r->alert_level !== null && (float) $r->current_stock <= (float) $r->alert_level => 'Low',
                default => 'OK',
            },
        ])->values()->all();
    }
}
