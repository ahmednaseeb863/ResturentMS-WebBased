<?php

namespace App\Reports\Cash;

use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** Deliveries and cash on delivery per rider. */
class RiderDeliveries extends Report
{
    public function key(): string
    {
        return 'riders';
    }

    public function group(): string
    {
        return 'cash';
    }

    public function title(): string
    {
        return 'Rider Deliveries & COD';
    }

    public function description(): string
    {
        return 'Deliveries per rider — delivered, failed, cash collected, settled, still held';
    }

    public function icon(): string
    {
        return 'bike';
    }

    public function chart(): ?array
    {
        return ['label' => 'rider', 'value' => 'delivered'];
    }

    public function columns(): array
    {
        return [
            static::col('rider', 'Rider'),
            static::col('trips', 'Deliveries', 'int', true),
            static::col('delivered', 'Delivered', 'int', true),
            static::col('failed', 'Failed / Returned', 'int', true),
            static::col('fees', 'Delivery Fees', 'money', true),
            static::col('collected', 'Cash Collected', 'money', true),
            static::col('settled', 'Settled', 'money', true),
            static::col('held', 'Still Held', 'money', true),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        return static::inDates(static::inBranches(DB::table('deliveries as d'), $f, 'd.branch_id'), $f, 'o.business_date')
            ->join('orders as o', 'o.id', '=', 'd.order_id')
            ->join('employees as e', 'e.id', '=', 'd.rider_id')
            ->selectRaw("e.name, count(*) as n,
                sum(case when d.status = 'delivered' then 1 else 0 end) as delivered,
                sum(case when d.status in ('failed', 'returned') then 1 else 0 end) as failed,
                sum(case when d.status = 'delivered' then d.fee else 0 end) as fees,
                sum(coalesce(d.cash_collected, 0)) as collected,
                sum(case when d.settled_at is not null then coalesce(d.cash_collected, 0) else 0 end) as settled")
            ->groupBy('e.id', 'e.name')
            ->orderBy('e.name')
            ->get()
            ->map(fn ($r) => [
                'rider' => $r->name,
                'trips' => (int) $r->n,
                'delivered' => (int) $r->delivered,
                'failed' => (int) $r->failed,
                'fees' => (float) $r->fees,
                'collected' => (float) $r->collected,
                'settled' => (float) $r->settled,
                'held' => round((float) $r->collected - (float) $r->settled, 2),
            ])->all();
    }
}
