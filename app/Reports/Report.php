<?php

namespace App\Reports;

use App\Enums\OrderStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One report (PLAN §4.19): columns, rows for the filters, a totals row and optionally a bar
 * chart. Figures come from `business_date`, never `created_at`. Every query filters the
 * branches itself (`inBranches`), so a report can cover one branch or several.
 *
 * Column types: text, date, int, qty, money, percent. `total: true` columns are summed in
 * the totals row (override `totals()` for averages / percentages).
 */
abstract class Report
{
    /** Report groups = sections of the Reports screen; each is one permission (route). */
    public const GROUPS = [
        'sales' => ['title' => 'Sales Reports', 'route' => 'reports.sales'],
        'cash' => ['title' => 'Cash, Shifts & Delivery', 'route' => 'reports.cash'],
        'inventory' => ['title' => 'Inventory Reports', 'route' => 'reports.inventory'],
        'finance' => ['title' => 'Financial Reports', 'route' => 'reports.finance'],
    ];

    abstract public function key(): string;

    abstract public function group(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /** @return list<array{key: string, label: string, type: string, total: bool}> */
    abstract public function columns(): array;

    /** @return list<array<string, mixed>> */
    abstract public function rows(ReportFilters $f): array;

    /** lucide icon name for the Reports screen card. */
    public function icon(): string
    {
        return 'file-text';
    }

    /** False for "as of now" reports (stock levels, balances). */
    public function usesDates(): bool
    {
        return true;
    }

    /** Bar chart: `['label' => column, 'value' => column]`, or null. */
    public function chart(): ?array
    {
        return null;
    }

    /** Wide reports print landscape. */
    public function landscape(): bool
    {
        return count($this->columns()) > 7;
    }

    /** @param  list<array<string, mixed>>  $rows */
    public function totals(array $rows): ?array
    {
        $summed = array_filter($this->columns(), fn ($c) => $c['total']);
        if (! $summed || ! $rows) {
            return null;
        }

        $totals = [$this->columns()[0]['key'] => 'Total'];
        foreach ($summed as $c) {
            $totals[$c['key']] = round(array_sum(array_map(fn ($r) => (float) ($r[$c['key']] ?? 0), $rows)), $c['type'] === 'qty' ? 3 : 2);
        }

        return $totals;
    }

    protected static function col(string $key, string $label, string $type = 'text', bool $total = false): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type, 'total' => $total];
    }

    protected static function inBranches(Builder $query, ReportFilters $f, string $column = 'branch_id'): Builder
    {
        return $query->whereIn($column, $f->branchIds());
    }

    protected static function inDates(Builder $query, ReportFilters $f, string $column = 'business_date'): Builder
    {
        return $query->whereBetween($column, [$f->from, $f->to]);
    }

    /** Orders that count as sales: placed (not held) and not cancelled, in the range. */
    protected static function salesOrders(ReportFilters $f): Builder
    {
        return static::inDates(static::inBranches(DB::table('orders'), $f, 'orders.branch_id'), $f, 'orders.business_date')
            ->whereNotIn('orders.status', [OrderStatus::Draft->value, OrderStatus::Cancelled->value]);
    }

    /** Sold lines (not voided) of the sales orders; top level only unless `$all`. */
    protected static function soldLines(ReportFilters $f, bool $all = false): Builder
    {
        return DB::table('order_items')
            ->joinSub(static::salesOrders($f)->select('orders.id', 'orders.business_date', 'orders.branch_id'), 'o', 'o.id', '=', 'order_items.order_id')
            ->whereNull('order_items.voided_at')
            ->when(! $all, fn ($q) => $q->whereNull('order_items.parent_order_item_id'));
    }

    /**
     * Cost of a stock movement row (its own cost, else the item's average cost for old
     * rows without one). Needs `stock_movements as sm` and the joins of `costJoins()`.
     */
    protected static function movementCost(): string
    {
        return '(-sm.quantity * COALESCE(sm.unit_cost, rm.avg_cost, ri.avg_cost, 0))';
    }

    protected static function costJoins(Builder $query): Builder
    {
        return $query
            ->leftJoin('raw_materials as rm', fn ($j) => $j->on('rm.id', '=', 'sm.stockable_id')->where('sm.stockable_type', 'raw_material'))
            ->leftJoin('ready_items as ri', fn ($j) => $j->on('ri.id', '=', 'sm.stockable_id')->where('sm.stockable_type', 'ready_item'));
    }

    /** Percentage with one decimal, 0 when the base is 0. */
    protected static function pct(float $part, float $whole): float
    {
        return $whole != 0 ? round($part / $whole * 100, 1) : 0.0;
    }
}
