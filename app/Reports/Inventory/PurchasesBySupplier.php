<?php

namespace App\Reports\Inventory;

use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** Purchases per supplier in the period, returns, payments and what is owed now. */
class PurchasesBySupplier extends Report
{
    public function key(): string
    {
        return 'purchases';
    }

    public function group(): string
    {
        return 'inventory';
    }

    public function title(): string
    {
        return 'Purchases by Supplier';
    }

    public function description(): string
    {
        return 'Billed, returned and paid per supplier — and what is still owed';
    }

    public function icon(): string
    {
        return 'truck';
    }

    public function chart(): ?array
    {
        return ['label' => 'supplier', 'value' => 'billed'];
    }

    public function columns(): array
    {
        return [
            static::col('supplier', 'Supplier'),
            static::col('purchases', 'Purchases', 'int', true),
            static::col('billed', 'Billed', 'money', true),
            static::col('returned', 'Returned', 'money', true),
            static::col('paid', 'Paid in Period', 'money', true),
            static::col('due', 'Owed Now', 'money', true),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        $bills = static::inDates(static::inBranches(DB::table('purchases as p'), $f, 'p.branch_id'), $f, 'p.business_date')
            ->selectRaw('p.supplier_id, count(*) as n, sum(p.total) as billed, sum(p.returned_total) as returned')
            ->groupBy('p.supplier_id')->get()->keyBy('supplier_id');
        $paid = static::inDates(static::inBranches(DB::table('supplier_payments'), $f), $f)
            ->selectRaw('supplier_id, sum(amount) as t')->groupBy('supplier_id')->pluck('t', 'supplier_id');
        $owedBills = static::inBranches(DB::table('purchases'), $f)->selectRaw('supplier_id, sum(total - returned_total) as t')->groupBy('supplier_id')->pluck('t', 'supplier_id');
        $owedPaid = static::inBranches(DB::table('supplier_payments'), $f)->selectRaw('supplier_id, sum(amount) as t')->groupBy('supplier_id')->pluck('t', 'supplier_id');

        $ids = $bills->keys()->merge($paid->keys())->unique();
        $names = DB::table('suppliers')->whereIn('id', $ids)->pluck('name', 'id');

        return $ids->map(fn ($id) => [
            'supplier' => $names[$id] ?? '—',
            'purchases' => (int) ($bills[$id]->n ?? 0),
            'billed' => (float) ($bills[$id]->billed ?? 0),
            'returned' => (float) ($bills[$id]->returned ?? 0),
            'paid' => (float) ($paid[$id] ?? 0),
            'due' => round((float) ($owedBills[$id] ?? 0) - (float) ($owedPaid[$id] ?? 0), 2),
        ])->sortByDesc('billed')->values()->all();
    }
}
