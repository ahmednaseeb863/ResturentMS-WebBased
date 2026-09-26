<?php

namespace App\Reports\Inventory;

use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** Who confirmed kitchen consumption and how often it differed from the recipe. */
class CookVariance extends Report
{
    public function key(): string
    {
        return 'cooks';
    }

    public function group(): string
    {
        return 'inventory';
    }

    public function title(): string
    {
        return 'Consumption by Cook';
    }

    public function description(): string
    {
        return 'Who confirmed raw materials — lines changed, used more than the recipe';
    }

    public function icon(): string
    {
        return 'chef-hat';
    }

    public function columns(): array
    {
        return [
            static::col('cook', 'Confirmed By'),
            static::col('lines', 'Lines', 'int', true),
            static::col('changed', 'Changed', 'int', true),
            static::col('over', 'Used More', 'int', true),
            static::col('under', 'Used Less', 'int', true),
            static::col('changed_pct', 'Changed %', 'percent'),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        return static::inDates(static::inBranches(DB::table('order_item_consumptions as c'), $f, 'c.branch_id'), $f, 'c.business_date')
            ->where('c.expected_qty', '>', 0)
            ->leftJoin('admins as a', 'a.id', '=', 'c.confirmed_by')
            ->selectRaw("case when c.auto = 1 then 'Auto-confirmed' else coalesce(a.name, '—') end as cook,
                count(*) as n,
                sum(case when c.actual_qty <> c.expected_qty then 1 else 0 end) as changed,
                sum(case when c.actual_qty > c.expected_qty then 1 else 0 end) as over_n,
                sum(case when c.actual_qty < c.expected_qty then 1 else 0 end) as under_n")
            ->groupBy('cook')
            ->orderByDesc('n')
            ->get()
            ->map(fn ($r) => [
                'cook' => $r->cook,
                'lines' => (int) $r->n,
                'changed' => (int) $r->changed,
                'over' => (int) $r->over_n,
                'under' => (int) $r->under_n,
                'changed_pct' => static::pct((float) $r->changed, (float) $r->n),
            ])->all();
    }
}
