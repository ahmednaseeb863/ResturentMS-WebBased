<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** Branches side by side: sales, refunds, expenses. */
class BranchComparison extends Report
{
    public function key(): string
    {
        return 'branches';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Branch Comparison';
    }

    public function description(): string
    {
        return 'Branches side by side — pick "All branches"';
    }

    public function icon(): string
    {
        return 'store';
    }

    public function chart(): ?array
    {
        return ['label' => 'branch', 'value' => 'net'];
    }

    public function columns(): array
    {
        return [
            static::col('branch', 'Branch'),
            static::col('orders', 'Orders', 'int', true),
            static::col('total', 'Sales', 'money', true),
            static::col('average', 'Average Bill', 'money'),
            static::col('refunds', 'Refunds', 'money', true),
            static::col('net', 'Net Sales', 'money', true),
            static::col('expenses', 'Expenses', 'money', true),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        $sales = static::salesOrders($f)->selectRaw('orders.branch_id as b, count(*) as n, sum(grand_total) as total')->groupBy('orders.branch_id')->get()->keyBy('b');
        $refunds = static::inDates(static::inBranches(DB::table('refunds'), $f), $f)->selectRaw('branch_id as b, sum(amount) as t')->groupBy('branch_id')->pluck('t', 'b');
        $expenses = static::inDates(static::inBranches(DB::table('expenses'), $f), $f)->whereNull('voided_at')->selectRaw('branch_id as b, sum(amount) as t')->groupBy('branch_id')->pluck('t', 'b');

        return $f->branches->map(function ($branch) use ($sales, $refunds, $expenses) {
            $s = $sales[$branch->id] ?? null;
            $total = (float) ($s->total ?? 0);
            $refund = (float) ($refunds[$branch->id] ?? 0);

            return [
                'branch' => $branch->name,
                'orders' => (int) ($s->n ?? 0),
                'total' => $total,
                'average' => ($s->n ?? 0) ? round($total / $s->n, 2) : 0,
                'refunds' => $refund,
                'net' => round($total - $refund, 2),
                'expenses' => (float) ($expenses[$branch->id] ?? 0),
            ];
        })->values()->all();
    }
}
