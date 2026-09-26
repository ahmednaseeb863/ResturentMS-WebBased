<?php

namespace App\Reports\Inventory;

use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** Waste and damage written off, at average cost. */
class WasteReport extends Report
{
    public function key(): string
    {
        return 'waste';
    }

    public function group(): string
    {
        return 'inventory';
    }

    public function title(): string
    {
        return 'Waste & Damage';
    }

    public function description(): string
    {
        return 'Stock written off — item, quantity, value, reason';
    }

    public function icon(): string
    {
        return 'trash-2';
    }

    public function columns(): array
    {
        return [
            static::col('date', 'Business Day', 'date'),
            static::col('code', 'Record'),
            static::col('item', 'Item'),
            static::col('qty', 'Qty'),
            static::col('value', 'Value', 'money', true),
            static::col('reason', 'Reason'),
            static::col('by', 'By'),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        return static::inDates(static::inBranches(DB::table('stock_adjustments as a'), $f, 'a.branch_id'), $f, 'a.business_date')
            ->join('stock_adjustment_items as i', 'i.stock_adjustment_id', '=', 'a.id')
            ->leftJoin('units as u', 'u.id', '=', 'i.unit_id')
            ->leftJoin('admins as ad', 'ad.id', '=', 'a.admin_id')
            ->select('a.business_date', 'a.number', 'a.reason', 'i.item_name', 'i.quantity', 'u.short_name as unit', 'i.line_cost', 'ad.name as by_name')
            ->orderBy('a.business_date')->orderBy('a.number')
            ->get()
            ->map(fn ($r) => [
                'date' => substr((string) $r->business_date, 0, 10),
                'code' => 'W-'.str_pad((string) $r->number, 4, '0', STR_PAD_LEFT),
                'item' => $r->item_name,
                'qty' => rtrim(rtrim(number_format((float) $r->quantity, 3, '.', ''), '0'), '.').' '.$r->unit,
                'value' => (float) $r->line_cost,
                'reason' => $r->reason,
                'by' => $r->by_name,
            ])->all();
    }
}
