<?php

namespace App\Reports;

use App\Models\Admin;

/** Every report, in the order of the Reports screen (PLAN §4.19). */
class ReportRegistry
{
    /** @var list<class-string<Report>> */
    public const REPORTS = [
        Sales\DailySales::class,
        Sales\OrderTypeSales::class,
        Sales\HourlySales::class,
        Sales\ItemSales::class,
        Sales\CategorySales::class,
        Sales\DealSales::class,
        Sales\WaiterSales::class,
        Sales\TopCustomers::class,
        Sales\BranchComparison::class,
        Sales\DiscountReport::class,
        Sales\VoidReport::class,
        Sales\RefundReport::class,
        Sales\CancelledOrders::class,
        Sales\TaxReport::class,
        Cash\PaymentMethods::class,
        Cash\ShiftReport::class,
        Cash\CashierOverShort::class,
        Cash\RiderDeliveries::class,
        Cash\ExpensesByCategory::class,
        Cash\ExpenseList::class,
        Inventory\StockLevels::class,
        Inventory\StockMovementSummary::class,
        Inventory\ConsumptionVariance::class,
        Inventory\CookVariance::class,
        Inventory\WasteReport::class,
        Inventory\PurchasesBySupplier::class,
        Finance\ProfitLoss::class,
        Finance\FoodCost::class,
        Finance\MenuItemProfit::class,
        Finance\SupplierBalances::class,
    ];

    /** @return list<Report> */
    public static function all(): array
    {
        return array_map(fn ($class) => app($class), self::REPORTS);
    }

    public static function find(string $group, string $key): ?Report
    {
        foreach (static::all() as $report) {
            if ($report->group() === $group && $report->key() === $key) {
                return $report;
            }
        }

        return null;
    }

    /** Reports screen: groups the admin may open, each with its report cards. */
    public static function menu(Admin $admin): array
    {
        $groups = [];
        foreach (Report::GROUPS as $group => $meta) {
            if (! $admin->canRoute($meta['route'])) {
                continue;
            }
            $groups[] = [
                'key' => $group,
                'title' => $meta['title'],
                'reports' => array_values(array_map(fn (Report $r) => [
                    'key' => $r->key(),
                    'title' => $r->title(),
                    'description' => $r->description(),
                    'icon' => $r->icon(),
                    'url' => route($meta['route'], $r->key()),
                ], array_filter(static::all(), fn (Report $r) => $r->group() === $group))),
            ];
        }

        return $groups;
    }
}
