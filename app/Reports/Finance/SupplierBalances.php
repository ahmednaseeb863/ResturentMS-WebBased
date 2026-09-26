<?php

namespace App\Reports\Finance;

use App\Reports\Report;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** What each supplier is owed now (all time). */
class SupplierBalances extends Report
{
    public function key(): string
    {
        return 'supplier-balances';
    }

    public function group(): string
    {
        return 'finance';
    }

    public function title(): string
    {
        return 'Supplier Balances';
    }

    public function description(): string
    {
        return 'Owed to each supplier now — billed, returned, paid';
    }

    public function icon(): string
    {
        return 'building-2';
    }

    public function usesDates(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [
            static::col('supplier', 'Supplier'),
            static::col('billed', 'Billed', 'money', true),
            static::col('returned', 'Returned', 'money', true),
            static::col('paid', 'Paid', 'money', true),
            static::col('balance', 'Balance', 'money', true),
        ];
    }

    public function rows(ReportFilters $f): array
    {
        $bills = static::inBranches(DB::table('purchases'), $f)->selectRaw('supplier_id, sum(total) as billed, sum(returned_total) as returned')->groupBy('supplier_id')->get()->keyBy('supplier_id');
        $paid = static::inBranches(DB::table('supplier_payments'), $f)->selectRaw('supplier_id, sum(amount) as t')->groupBy('supplier_id')->pluck('t', 'supplier_id');
        $ids = $bills->keys()->merge($paid->keys())->unique();
        $names = DB::table('suppliers')->whereIn('id', $ids)->pluck('name', 'id');

        return $ids->map(function ($id) use ($bills, $paid, $names) {
            $billed = (float) ($bills[$id]->billed ?? 0);
            $returned = (float) ($bills[$id]->returned ?? 0);
            $p = (float) ($paid[$id] ?? 0);

            return ['supplier' => $names[$id] ?? '—', 'billed' => $billed, 'returned' => $returned, 'paid' => $p, 'balance' => round($billed - $returned - $p, 2)];
        })->sortByDesc('balance')->values()->all();
    }
}
