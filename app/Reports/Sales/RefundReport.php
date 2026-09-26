<?php

namespace App\Reports\Sales;

use App\Enums\PaymentMethod;
use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** Refunds: order, method, amount, reason, who refunded and approved. */
class RefundReport extends Report
{
    public function key(): string
    {
        return 'refunds';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Refunds';
    }

    public function description(): string
    {
        return 'Money given back — cash or transfer, reason, approval';
    }

    public function icon(): string
    {
        return 'undo-2';
    }

    public function columns(): array
    {
        return [
            static::col('date', 'Business Day', 'date'),
            static::col('order', 'Order'),
            static::col('method', 'Method'),
            static::col('amount', 'Amount', 'money', true),
            static::col('reason', 'Reason'),
            static::col('by', 'Refunded By'),
            static::col('approved', 'Approved By'),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        return static::inDates(static::inBranches(DB::table('refunds as r'), $f, 'r.branch_id'), $f, 'r.business_date')
            ->join('orders as o', 'o.id', '=', 'r.order_id')
            ->leftJoin('bank_accounts as b', 'b.id', '=', 'r.bank_account_id')
            ->leftJoin('admins as a', 'a.id', '=', 'r.refunded_by')
            ->leftJoin('admins as ap', 'ap.id', '=', 'r.approved_by')
            ->select('r.business_date', 'o.order_number', 'r.method', 'b.bank_name', 'r.amount', 'r.reason', 'a.name as by_name', 'ap.name as approved_name')
            ->orderBy('r.id')
            ->get()
            ->map(fn ($r) => [
                'date' => substr((string) $r->business_date, 0, 10),
                'order' => $r->order_number,
                'method' => (PaymentMethod::tryFrom($r->method)?->label() ?? $r->method).($r->bank_name ? " · {$r->bank_name}" : ''),
                'amount' => (float) $r->amount,
                'reason' => $r->reason,
                'by' => $r->by_name,
                'approved' => $r->approved_name,
            ])->all();
    }
}
