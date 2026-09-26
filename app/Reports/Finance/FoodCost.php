<?php

namespace App\Reports\Finance;

use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** Food cost % per business day: cost of goods (kitchen use + ready items sold) vs sales before tax. */
class FoodCost extends Report
{
    public function key(): string
    {
        return 'food-cost';
    }

    public function group(): string
    {
        return 'finance';
    }

    public function title(): string
    {
        return 'Food Cost %';
    }

    public function description(): string
    {
        return 'Cost of goods (kitchen use + ready items sold) against sales before tax, per day';
    }

    public function icon(): string
    {
        return 'percent';
    }

    public function chart(): ?array
    {
        return ['label' => 'date', 'value' => 'cost_pct'];
    }

    public function columns(): array
    {
        return [
            static::col('date', 'Business Day', 'date'),
            static::col('sales', 'Sales (before tax)', 'money', true),
            static::col('kitchen', 'Kitchen Use', 'money', true),
            static::col('ready', 'Ready Items Sold', 'money', true),
            static::col('cost', 'Cost of Goods', 'money', true),
            static::col('cost_pct', 'Food Cost %', 'percent'),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        $sales = static::salesOrders($f)
            ->selectRaw('orders.business_date as d, sum(grand_total - tax_total - round_off) as t')
            ->groupBy('orders.business_date')->get()
            ->mapWithKeys(fn ($r) => [substr((string) $r->d, 0, 10) => (float) $r->t]);

        $costs = static::costJoins(static::inDates(static::inBranches(DB::table('stock_movements as sm'), $f, 'sm.branch_id'), $f, 'sm.business_date'))
            ->whereIn('sm.type', ['consumption', 'sale', 'sale_return'])
            ->selectRaw("sm.business_date as d, sum(case when sm.type = 'consumption' then ".static::movementCost()." else 0 end) as kitchen, sum(case when sm.type <> 'consumption' then ".static::movementCost().' else 0 end) as ready')
            ->groupBy('sm.business_date')->get()
            ->keyBy(fn ($r) => substr((string) $r->d, 0, 10));

        $rows = [];
        foreach ($f->days() as $day) {
            $s = $sales[$day] ?? 0.0;
            $c = $costs[$day] ?? null;
            if (! $s && ! $c) {
                continue;
            }
            $kitchen = round((float) ($c->kitchen ?? 0), 2);
            $ready = round((float) ($c->ready ?? 0), 2);
            $rows[] = [
                'date' => $day,
                'sales' => round($s, 2),
                'kitchen' => $kitchen,
                'ready' => $ready,
                'cost' => round($kitchen + $ready, 2),
                'cost_pct' => static::pct($kitchen + $ready, $s),
            ];
        }

        return $rows;
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
