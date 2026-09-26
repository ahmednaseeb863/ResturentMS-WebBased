<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** Sales per business day: bill parts, tax, refunds and what was kept. */
class DailySales extends Report
{
    public function key(): string
    {
        return 'daily-sales';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Daily Sales';
    }

    public function description(): string
    {
        return 'Sales summary by business day — discounts, service charge, tax, refunds';
    }

    public function icon(): string
    {
        return 'trending-up';
    }

    public function chart(): ?array
    {
        return ['label' => 'date', 'value' => 'net'];
    }

    public function columns(): array
    {
        return [
            static::col('date', 'Business Day', 'date'),
            static::col('orders', 'Orders', 'int', true),
            static::col('items', 'Item Sales', 'money', true),
            static::col('discounts', 'Discounts', 'money', true),
            static::col('service', 'Service Charge', 'money', true),
            static::col('delivery', 'Delivery Fees', 'money', true),
            static::col('tax', 'Tax', 'money', true),
            static::col('total', 'Bill Total', 'money', true),
            static::col('refunds', 'Refunds', 'money', true),
            static::col('net', 'Net Sales', 'money', true),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        $orders = static::salesOrders($f)
            ->selectRaw('orders.business_date as d, count(*) as n, sum(items_total) as items, sum(discount_total) as disc, sum(service_charge) as sc, sum(delivery_fee) as del, sum(tax_total) as tax, sum(grand_total) as total')
            ->groupBy('orders.business_date')
            ->get()
            ->keyBy(fn ($r) => substr((string) $r->d, 0, 10));

        $refunds = static::inDates(static::inBranches(DB::table('refunds'), $f), $f)
            ->selectRaw('business_date as d, sum(amount) as amount')
            ->groupBy('business_date')
            ->get()
            ->mapWithKeys(fn ($r) => [substr((string) $r->d, 0, 10) => (float) $r->amount]);

        $rows = [];
        foreach ($f->days() as $day) {
            $o = $orders[$day] ?? null;
            $refund = $refunds[$day] ?? 0.0;
            if (! $o && ! $refund) {
                continue;
            }
            $rows[] = [
                'date' => $day,
                'orders' => (int) ($o->n ?? 0),
                'items' => (float) ($o->items ?? 0),
                'discounts' => (float) ($o->disc ?? 0),
                'service' => (float) ($o->sc ?? 0),
                'delivery' => (float) ($o->del ?? 0),
                'tax' => (float) ($o->tax ?? 0),
                'total' => (float) ($o->total ?? 0),
                'refunds' => $refund,
                'net' => round((float) ($o->total ?? 0) - $refund, 2),
            ];
        }

        return $rows;
    }
}
