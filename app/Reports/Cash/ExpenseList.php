<?php

namespace App\Reports\Cash;

use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** Every expense in the period. */
class ExpenseList extends Report
{
    public function key(): string
    {
        return 'expense-list';
    }

    public function group(): string
    {
        return 'cash';
    }

    public function title(): string
    {
        return 'Expense Register';
    }

    public function description(): string
    {
        return 'Every expense — category, description, paid from, amount';
    }

    public function icon(): string
    {
        return 'scroll-text';
    }

    public function columns(): array
    {
        return [
            static::col('date', 'Business Day', 'date'),
            static::col('code', 'Expense'),
            static::col('category', 'Category'),
            static::col('description', 'Description'),
            static::col('paid_from', 'Paid From'),
            static::col('reference', 'Reference'),
            static::col('amount', 'Amount', 'money', true),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        return static::inDates(static::inBranches(DB::table('expenses as x'), $f, 'x.branch_id'), $f, 'x.business_date')
            ->whereNull('x.voided_at')
            ->join('expense_categories as c', 'c.id', '=', 'x.expense_category_id')
            ->leftJoin('bank_accounts as b', 'b.id', '=', 'x.bank_account_id')
            ->leftJoin('shifts as s', 's.id', '=', 'x.shift_id')
            ->select('x.business_date', 'x.number', 'x.description', 'x.paid_from', 'x.reference_no', 'x.amount', 'c.name as category', 'b.bank_name', 's.number as shift')
            ->orderBy('x.business_date')->orderBy('x.number')
            ->get()
            ->map(fn ($r) => [
                'date' => substr((string) $r->business_date, 0, 10),
                'code' => 'EXP-'.str_pad((string) $r->number, 4, '0', STR_PAD_LEFT),
                'category' => $r->category,
                'description' => $r->description,
                'paid_from' => $r->paid_from === 'cash' ? 'Cash · SHF-'.str_pad((string) $r->shift, 3, '0', STR_PAD_LEFT) : 'Bank · '.$r->bank_name,
                'reference' => $r->reference_no,
                'amount' => (float) $r->amount,
            ])->all();
    }
}
