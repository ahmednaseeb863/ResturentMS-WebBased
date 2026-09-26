<?php

namespace App\Reports\Cash;

use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** Shifts (X / Z): counter, cashier, expected vs counted cash, over / short. */
class ShiftReport extends Report
{
    public function key(): string
    {
        return 'shifts';
    }

    public function group(): string
    {
        return 'cash';
    }

    public function title(): string
    {
        return 'Shifts & Cash Over / Short';
    }

    public function description(): string
    {
        return 'Every shift — expected vs counted cash, difference, who opened and closed';
    }

    public function icon(): string
    {
        return 'clock';
    }

    public function landscape(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            static::col('shift', 'Shift'),
            static::col('date', 'Business Day', 'date'),
            static::col('counter', 'Counter'),
            static::col('opened_by', 'Opened By'),
            static::col('closed_by', 'Closed By'),
            static::col('opening', 'Opening', 'money'),
            static::col('expected', 'Expected', 'money', true),
            static::col('counted', 'Counted', 'money', true),
            static::col('difference', 'Over / Short', 'money', true),
            static::col('status', 'Status'),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        return static::inDates(static::inBranches(DB::table('shifts as s'), $f, 's.branch_id'), $f, 's.business_date')
            ->leftJoin('cash_counters as c', 'c.id', '=', 's.cash_counter_id')
            ->leftJoin('shift_types as t', 't.id', '=', 's.shift_type_id')
            ->leftJoin('admins as o', 'o.id', '=', 's.opened_by')
            ->leftJoin('admins as cl', 'cl.id', '=', 's.closed_by')
            ->select('s.number', 's.business_date', 's.status', 's.opening_cash', 's.expected_cash', 's.counted_cash', 's.difference', 'c.name as counter', 't.name as type', 'o.name as opened', 'cl.name as closed')
            ->orderBy('s.business_date')->orderBy('s.number')
            ->get()
            ->map(fn ($r) => [
                'shift' => 'SHF-'.str_pad((string) $r->number, 3, '0', STR_PAD_LEFT),
                'date' => substr((string) $r->business_date, 0, 10),
                'counter' => $r->counter.($r->type ? " · {$r->type}" : ''),
                'opened_by' => $r->opened,
                'closed_by' => $r->closed,
                'opening' => (float) $r->opening_cash,
                'expected' => (float) $r->expected_cash,
                'counted' => (float) $r->counted_cash,
                'difference' => (float) $r->difference,
                'status' => ucfirst($r->status),
            ])->all();
    }
}
