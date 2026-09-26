<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportFilters;

/** Every discount given: order, discount, amount, who approved it and why. */
class DiscountReport extends Report
{
    public function key(): string
    {
        return 'discounts';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Discounts Given';
    }

    public function description(): string
    {
        return 'Every bill and item discount — amount, approval and reason';
    }

    public function icon(): string
    {
        return 'percent';
    }

    public function columns(): array
    {
        return [
            static::col('date', 'Business Day', 'date'),
            static::col('order', 'Order'),
            static::col('discount', 'Discount'),
            static::col('on', 'On'),
            static::col('amount', 'Amount', 'money', true),
            static::col('by', 'Given By'),
            static::col('approved', 'Approved By'),
            static::col('reason', 'Reason'),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        return static::salesOrders($f)
            ->join('order_discounts as od', 'od.order_id', '=', 'orders.id')
            ->whereNull('od.deleted_at')
            ->leftJoin('order_items as oi', 'oi.id', '=', 'od.order_item_id')
            ->leftJoin('admins as c', 'c.id', '=', 'od.created_by')
            ->leftJoin('admins as a', 'a.id', '=', 'od.approved_by')
            ->select('orders.business_date', 'orders.order_number', 'od.name', 'od.amount', 'od.reason', 'oi.item_name', 'c.name as by_name', 'a.name as approved_name')
            ->orderBy('orders.business_date')->orderBy('od.id')
            ->get()
            ->map(fn ($r) => [
                'date' => substr((string) $r->business_date, 0, 10),
                'order' => $r->order_number,
                'discount' => $r->name,
                'on' => $r->item_name ?? 'Bill',
                'amount' => (float) $r->amount,
                'by' => $r->by_name,
                'approved' => $r->approved_name,
                'reason' => $r->reason,
            ])->all();
    }
}
