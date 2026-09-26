<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportFilters;

/** Tax and service charge collected per business day. */
class TaxReport extends Report
{
    public function key(): string
    {
        return 'tax';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Tax & Service Charge';
    }

    public function description(): string
    {
        return 'Taxable sales, tax and service charge collected per day';
    }

    public function icon(): string
    {
        return 'landmark';
    }

    public function columns(): array
    {
        return [
            static::col('date', 'Business Day', 'date'),
            static::col('orders', 'Orders', 'int', true),
            static::col('taxable', 'Taxable Amount', 'money', true),
            static::col('tax', 'Tax', 'money', true),
            static::col('service', 'Service Charge', 'money', true),
            static::col('delivery', 'Delivery Fees', 'money', true),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        return static::salesOrders($f)
            ->selectRaw('orders.business_date as d, count(*) as n, sum(grand_total - tax_total - round_off) as taxable, sum(tax_total) as tax, sum(service_charge) as sc, sum(delivery_fee) as del')
            ->groupBy('orders.business_date')
            ->orderBy('orders.business_date')
            ->get()
            ->map(fn ($r) => [
                'date' => substr((string) $r->d, 0, 10),
                'orders' => (int) $r->n,
                'taxable' => (float) $r->taxable,
                'tax' => (float) $r->tax,
                'service' => (float) $r->sc,
                'delivery' => (float) $r->del,
            ])->all();
    }
}
