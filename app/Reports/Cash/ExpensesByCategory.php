<?php

namespace App\Reports\Cash;

use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** Expenses per category, cash vs bank. */
class ExpensesByCategory extends Report
{
    public function key(): string
    {
        return 'expenses';
    }

    public function group(): string
    {
        return 'cash';
    }

    public function title(): string
    {
        return 'Expenses by Category';
    }

    public function description(): string
    {
        return 'Money spent per category — from drawers and bank accounts';
    }

    public function icon(): string
    {
        return 'receipt';
    }

    public function chart(): ?array
    {
        return ['label' => 'category', 'value' => 'total'];
    }

    public function columns(): array
    {
        return [
            static::col('category', 'Category'),
            static::col('count', 'Expenses', 'int', true),
            static::col('cash', 'Cash', 'money', true),
            static::col('bank', 'Bank', 'money', true),
            static::col('total', 'Total', 'money', true),
            static::col('share', 'Share', 'percent'),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        $rows = static::inDates(static::inBranches(DB::table('expenses as x'), $f, 'x.branch_id'), $f, 'x.business_date')
            ->whereNull('x.voided_at')
            ->join('expense_categories as c', 'c.id', '=', 'x.expense_category_id')
            ->selectRaw("c.name, count(*) as n, sum(case when x.paid_from = 'cash' then x.amount else 0 end) as cash, sum(case when x.paid_from = 'cash' then 0 else x.amount end) as bank, sum(x.amount) as total")
            ->groupBy('c.id', 'c.name')
            ->orderByDesc('total')
            ->get();
        $all = (float) $rows->sum('total');

        return $rows->map(fn ($r) => [
            'category' => $r->name,
            'count' => (int) $r->n,
            'cash' => (float) $r->cash,
            'bank' => (float) $r->bank,
            'total' => (float) $r->total,
            'share' => static::pct((float) $r->total, $all),
        ])->all();
    }
}
