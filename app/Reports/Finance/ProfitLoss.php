<?php

namespace App\Reports\Finance;

use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/**
 * Profit & loss for the period: sales before tax, less refunds, cost of goods, waste and
 * expenses. Tax collected is shown but is not income.
 */
class ProfitLoss extends Report
{
    public function key(): string
    {
        return 'profit-loss';
    }

    public function group(): string
    {
        return 'finance';
    }

    public function title(): string
    {
        return 'Profit & Loss';
    }

    public function description(): string
    {
        return 'Sales − refunds − cost of goods − waste − expenses = profit';
    }

    public function icon(): string
    {
        return 'banknote';
    }

    public function columns(): array
    {
        return [
            static::col('line', 'Line'),
            static::col('amount', 'Amount', 'money'),
            static::col('share', '% of Sales', 'percent'),
        ];
    }

    public function landscape(): bool
    {
        return false;
    }

    public function rows(ReportFilters $f): array
    {
        $o = static::salesOrders($f)
            ->selectRaw('sum(items_total) as items, sum(discount_total) as disc, sum(service_charge) as sc, sum(delivery_fee) as del, sum(round_off) as round_off, sum(tax_total) as tax')
            ->first();
        $refunds = (float) static::inDates(static::inBranches(DB::table('refunds'), $f), $f)->sum('amount');

        $cogs = static::costJoins(static::inDates(static::inBranches(DB::table('stock_movements as sm'), $f, 'sm.branch_id'), $f, 'sm.business_date'))
            ->selectRaw("sum(case when sm.type in ('consumption', 'sale', 'sale_return') then ".static::movementCost()." else 0 end) as goods, sum(case when sm.type = 'waste' then ".static::movementCost().' else 0 end) as waste')
            ->first();

        $expenses = static::inDates(static::inBranches(DB::table('expenses as x'), $f, 'x.branch_id'), $f, 'x.business_date')
            ->whereNull('x.voided_at')
            ->join('expense_categories as c', 'c.id', '=', 'x.expense_category_id')
            ->selectRaw('c.name, sum(x.amount) as t')->groupBy('c.id', 'c.name')->orderByDesc('t')->get();

        $items = (float) $o->items;
        $sales = round($items - (float) $o->disc + (float) $o->sc + (float) $o->del + (float) $o->round_off, 2);
        $netSales = round($sales - $refunds, 2);
        $goods = round((float) $cogs->goods, 2);
        $gross = round($netSales - $goods, 2);
        $waste = round((float) $cogs->waste, 2);
        $expenseTotal = round((float) $expenses->sum('t'), 2);
        $profit = round($gross - $waste - $expenseTotal, 2);

        $line = fn (string $label, float $amount, bool $strong = false) => [
            'line' => $label, 'amount' => $amount, 'share' => static::pct($amount, $netSales), 'strong' => $strong,
        ];

        return [
            $line('Item sales', $items),
            $line('− Discounts', -(float) $o->disc),
            $line('+ Service charge', (float) $o->sc),
            $line('+ Delivery fees', (float) $o->del),
            $line('+ Rounding', (float) $o->round_off),
            $line('− Refunds', -$refunds),
            $line('Net sales (before tax)', $netSales, true),
            $line('− Cost of goods (kitchen use + ready items)', -$goods),
            $line('Gross profit', $gross, true),
            $line('− Waste & damage', -$waste),
            ...$expenses->map(fn ($e) => $line('− Expense: '.$e->name, -(float) $e->t))->all(),
            $line('Net profit', $profit, true),
            ['line' => 'Tax collected (not income)', 'amount' => (float) $o->tax, 'share' => null, 'strong' => false],
        ];
    }

    public function totals(array $rows): ?array
    {
        return null;
    }
}
