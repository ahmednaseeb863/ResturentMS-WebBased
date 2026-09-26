<?php

namespace App\Reports\Cash;

use App\Enums\PaymentMethod;
use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** Money received by payment method and bank account, less refunds. */
class PaymentMethods extends Report
{
    public function key(): string
    {
        return 'payments';
    }

    public function group(): string
    {
        return 'cash';
    }

    public function title(): string
    {
        return 'Payments by Method';
    }

    public function description(): string
    {
        return 'Cash and bank transfers per account — received, refunded, kept';
    }

    public function icon(): string
    {
        return 'wallet';
    }

    public function chart(): ?array
    {
        return ['label' => 'method', 'value' => 'net'];
    }

    public function columns(): array
    {
        return [
            static::col('method', 'Method / Account'),
            static::col('count', 'Payments', 'int', true),
            static::col('received', 'Received', 'money', true),
            static::col('refunded', 'Refunded', 'money', true),
            static::col('net', 'Kept', 'money', true),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        $group = fn (string $table) => static::inDates(static::inBranches(DB::table("{$table} as p"), $f, 'p.branch_id'), $f, 'p.business_date')
            ->leftJoin('bank_accounts as b', 'b.id', '=', 'p.bank_account_id')
            ->selectRaw('p.method, b.bank_name, b.account_title, count(*) as n, sum(p.amount) as total')
            ->groupBy('p.method', 'p.bank_account_id', 'b.bank_name', 'b.account_title')
            ->get()
            ->keyBy(fn ($r) => $r->method.'|'.$r->bank_name.'|'.$r->account_title);

        $payments = $group('payments');
        $refunds = $group('refunds');

        return $payments->keys()->merge($refunds->keys())->unique()->sort()->map(function ($key) use ($payments, $refunds) {
            $p = $payments[$key] ?? null;
            $r = $refunds[$key] ?? null;
            $row = $p ?? $r;
            $label = PaymentMethod::tryFrom($row->method)?->label() ?? $row->method;

            return [
                'method' => $label.($row->bank_name ? " · {$row->bank_name}".($row->account_title ? " ({$row->account_title})" : '') : ''),
                'count' => (int) ($p->n ?? 0),
                'received' => (float) ($p->total ?? 0),
                'refunded' => (float) ($r->total ?? 0),
                'net' => round((float) ($p->total ?? 0) - (float) ($r->total ?? 0), 2),
            ];
        })->values()->all();
    }
}
