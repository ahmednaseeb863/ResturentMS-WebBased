<?php

namespace App\Reports\Sales;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** Cancelled orders: bill total, reason, who cancelled. */
class CancelledOrders extends Report
{
    public function key(): string
    {
        return 'cancelled';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Cancelled Orders';
    }

    public function description(): string
    {
        return 'Orders cancelled after they were placed — total, reason, who';
    }

    public function icon(): string
    {
        return 'x-circle';
    }

    public function columns(): array
    {
        return [
            static::col('date', 'Business Day', 'date'),
            static::col('order', 'Order'),
            static::col('type', 'Type'),
            static::col('total', 'Bill Total', 'money', true),
            static::col('reason', 'Reason'),
            static::col('by', 'Cancelled By'),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        return static::inDates(static::inBranches(DB::table('orders'), $f, 'orders.branch_id'), $f, 'orders.business_date')
            ->where('orders.status', OrderStatus::Cancelled->value)
            ->whereNotNull('orders.number')
            ->leftJoin('admins as a', 'a.id', '=', 'orders.cancelled_by')
            ->select('orders.business_date', 'orders.order_number', 'orders.type', 'orders.grand_total', 'orders.cancel_reason', 'a.name as by_name')
            ->orderBy('orders.cancelled_at')
            ->get()
            ->map(fn ($r) => [
                'date' => substr((string) $r->business_date, 0, 10),
                'order' => $r->order_number,
                'type' => OrderType::tryFrom($r->type)?->label() ?? $r->type,
                'total' => (float) $r->grand_total,
                'reason' => $r->cancel_reason,
                'by' => $r->by_name,
            ])->all();
    }
}
