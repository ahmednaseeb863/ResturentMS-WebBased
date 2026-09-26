<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportFilters;

/** Busy hours: orders and sales by the hour they were placed (branch time). */
class HourlySales extends Report
{
    public function key(): string
    {
        return 'hourly';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Sales by Hour';
    }

    public function description(): string
    {
        return 'Busy hours — orders and sales by the hour they were placed';
    }

    public function icon(): string
    {
        return 'clock';
    }

    public function chart(): ?array
    {
        return ['label' => 'hour', 'value' => 'total'];
    }

    public function columns(): array
    {
        return [
            static::col('hour', 'Hour'),
            static::col('orders', 'Orders', 'int', true),
            static::col('total', 'Sales', 'money', true),
            static::col('average', 'Average Bill', 'money'),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        return static::salesOrders($f)
            ->whereNotNull('placed_at')
            ->selectRaw("HOUR(CONVERT_TZ(placed_at, '+00:00', ?)) as h, count(*) as n, sum(grand_total) as total", [$f->utcOffset()])
            ->groupBy('h')
            ->orderBy('h')
            ->get()
            ->map(fn ($r) => [
                'hour' => sprintf('%02d:00', $r->h),
                'orders' => (int) $r->n,
                'total' => (float) $r->total,
                'average' => $r->n ? round($r->total / $r->n, 2) : 0,
            ])->all();
    }
}
