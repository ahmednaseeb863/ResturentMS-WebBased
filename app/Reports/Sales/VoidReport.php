<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** Voided lines: item, amount, reason, who voided, whether it was wasted. */
class VoidReport extends Report
{
    public function key(): string
    {
        return 'voids';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Voided Items';
    }

    public function description(): string
    {
        return 'Lines voided after sending — amount, reason, who, wasted or not';
    }

    public function icon(): string
    {
        return 'ban';
    }

    public function columns(): array
    {
        return [
            static::col('date', 'Business Day', 'date'),
            static::col('order', 'Order'),
            static::col('item', 'Item'),
            static::col('qty', 'Qty', 'int', true),
            static::col('amount', 'Amount', 'money', true),
            static::col('reason', 'Reason'),
            static::col('by', 'Voided By'),
            static::col('wasted', 'Wasted'),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        return static::inDates(static::inBranches(DB::table('orders'), $f, 'orders.branch_id'), $f, 'orders.business_date')
            ->join('order_items as oi', 'oi.order_id', '=', 'orders.id')
            ->whereNotNull('oi.voided_at')
            ->whereNull('oi.parent_order_item_id')
            ->leftJoin('admins as a', 'a.id', '=', 'oi.voided_by')
            ->select('orders.business_date', 'orders.order_number', 'oi.item_name', 'oi.variant_name', 'oi.quantity', 'oi.line_total', 'oi.void_reason', 'oi.void_wasted', 'a.name as by_name')
            ->orderBy('oi.voided_at')
            ->get()
            ->map(fn ($r) => [
                'date' => substr((string) $r->business_date, 0, 10),
                'order' => $r->order_number,
                'item' => $r->item_name.($r->variant_name ? " ({$r->variant_name})" : ''),
                'qty' => (int) $r->quantity,
                'amount' => (float) $r->line_total,
                'reason' => $r->void_reason,
                'by' => $r->by_name,
                'wasted' => $r->void_wasted ? 'Yes' : 'No',
            ])->all();
    }
}
