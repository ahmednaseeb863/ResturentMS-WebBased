<?php

namespace App\Reports\Cash;

use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** Cash over / short per cashier across their closed shifts. */
class CashierOverShort extends Report
{
    public function key(): string
    {
        return 'cashiers';
    }

    public function group(): string
    {
        return 'cash';
    }

    public function title(): string
    {
        return 'Over / Short by Cashier';
    }

    public function description(): string
    {
        return 'Closed shifts per cashier — total difference and short shifts';
    }

    public function icon(): string
    {
        return 'user-cog';
    }

    public function columns(): array
    {
        return [
            static::col('cashier', 'Cashier'),
            static::col('shifts', 'Shifts', 'int', true),
            static::col('expected', 'Expected', 'money', true),
            static::col('counted', 'Counted', 'money', true),
            static::col('difference', 'Over / Short', 'money', true),
            static::col('short', 'Short Shifts', 'int', true),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        return static::inDates(static::inBranches(DB::table('shifts as s'), $f, 's.branch_id'), $f, 's.business_date')
            ->where('s.status', 'closed')
            ->join('admins as a', 'a.id', '=', 's.opened_by')
            ->selectRaw('a.name, count(*) as n, sum(s.expected_cash) as expected, sum(s.counted_cash) as counted, sum(s.difference) as diff, sum(case when s.difference < 0 then 1 else 0 end) as short')
            ->groupBy('a.id', 'a.name')
            ->orderBy('diff')
            ->get()
            ->map(fn ($r) => [
                'cashier' => $r->name,
                'shifts' => (int) $r->n,
                'expected' => (float) $r->expected,
                'counted' => (float) $r->counted,
                'difference' => (float) $r->diff,
                'short' => (int) $r->short,
            ])->all();
    }
}
