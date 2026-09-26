<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportFilters;

/** Dine-in sales per waiter: orders, guests, sales, average bill. */
class WaiterSales extends Report
{
    public function key(): string
    {
        return 'waiters';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Waiter Sales';
    }

    public function description(): string
    {
        return 'Orders, guests and sales per waiter';
    }

    public function icon(): string
    {
        return 'concierge-bell';
    }

    public function chart(): ?array
    {
        return ['label' => 'waiter', 'value' => 'total'];
    }

    public function columns(): array
    {
        return [
            static::col('waiter', 'Waiter'),
            static::col('orders', 'Orders', 'int', true),
            static::col('guests', 'Guests', 'int', true),
            static::col('total', 'Sales', 'money', true),
            static::col('average', 'Average Bill', 'money'),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        return static::salesOrders($f)
            ->join('employees as e', 'e.id', '=', 'orders.waiter_id')
            ->selectRaw('e.name as waiter, count(*) as n, sum(coalesce(orders.guests, 0)) as guests, sum(orders.grand_total) as total')
            ->groupBy('e.id', 'e.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'waiter' => $r->waiter,
                'orders' => (int) $r->n,
                'guests' => (int) $r->guests,
                'total' => (float) $r->total,
                'average' => $r->n ? round($r->total / $r->n, 2) : 0,
            ])->all();
    }
}
