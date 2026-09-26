<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportFilters;

/** Best customers by money spent in the period. */
class TopCustomers extends Report
{
    public function key(): string
    {
        return 'customers';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Top Customers';
    }

    public function description(): string
    {
        return 'Customers by money spent — orders, average bill, last visit';
    }

    public function icon(): string
    {
        return 'user-round';
    }

    public function columns(): array
    {
        return [
            static::col('customer', 'Customer'),
            static::col('phone', 'Phone'),
            static::col('orders', 'Orders', 'int', true),
            static::col('total', 'Spent', 'money', true),
            static::col('average', 'Average Bill', 'money'),
            static::col('last', 'Last Visit', 'date'),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        return static::salesOrders($f)
            ->join('users as u', 'u.id', '=', 'orders.user_id')
            ->selectRaw('u.name, u.phone, count(*) as n, sum(orders.grand_total) as total, max(orders.business_date) as last')
            ->groupBy('u.id', 'u.name', 'u.phone')
            ->orderByDesc('total')
            ->limit(200)
            ->get()
            ->map(fn ($r) => [
                'customer' => $r->name,
                'phone' => $r->phone,
                'orders' => (int) $r->n,
                'total' => (float) $r->total,
                'average' => $r->n ? round($r->total / $r->n, 2) : 0,
                'last' => substr((string) $r->last, 0, 10),
            ])->all();
    }
}
