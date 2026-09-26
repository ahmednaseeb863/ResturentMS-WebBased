<?php

namespace App\Reports\Sales;

use App\Enums\OrderType;
use App\Reports\Report;
use App\Reports\ReportFilters;

/** Dine-in / takeaway / delivery: orders, share and average bill. */
class OrderTypeSales extends Report
{
    public function key(): string
    {
        return 'order-types';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Sales by Order Type';
    }

    public function description(): string
    {
        return 'Dine-in, takeaway and delivery — orders, share and average bill';
    }

    public function icon(): string
    {
        return 'pie-chart';
    }

    public function chart(): ?array
    {
        return ['label' => 'type', 'value' => 'total'];
    }

    public function columns(): array
    {
        return [
            static::col('type', 'Order Type'),
            static::col('orders', 'Orders', 'int', true),
            static::col('guests', 'Guests', 'int', true),
            static::col('total', 'Sales', 'money', true),
            static::col('share', 'Share', 'percent'),
            static::col('average', 'Average Bill', 'money'),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        $rows = static::salesOrders($f)
            ->selectRaw('type, count(*) as n, sum(coalesce(guests, 0)) as guests, sum(grand_total) as total')
            ->groupBy('type')
            ->orderByDesc('total')
            ->get();
        $all = (float) $rows->sum('total');

        return $rows->map(fn ($r) => [
            'type' => OrderType::tryFrom($r->type)?->label() ?? $r->type,
            'orders' => (int) $r->n,
            'guests' => (int) $r->guests,
            'total' => (float) $r->total,
            'share' => static::pct((float) $r->total, $all),
            'average' => $r->n ? round($r->total / $r->n, 2) : 0,
        ])->all();
    }

    public function totals(array $rows): ?array
    {
        $totals = parent::totals($rows);
        if ($totals) {
            $totals['share'] = 100;
            $totals['average'] = $totals['orders'] ? round($totals['total'] / $totals['orders'], 2) : 0;
        }

        return $totals;
    }
}
