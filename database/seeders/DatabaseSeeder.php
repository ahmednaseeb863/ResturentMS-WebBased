<?php

namespace Database\Seeders;

use App\Enums\DesignationType;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\CashCounter;
use App\Models\Designation;
use App\Models\ExpenseCategory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\ShiftType;
use App\Support\CurrentBranch;
use App\Support\Permissions\PermissionCatalog;
use App\Support\TrashablePivot;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Routes a screen needs to print kitchen tickets / receipts (print agent). */
    private const PRINT_DEVICE = ['print-jobs.pending', 'print-jobs.claim', 'print-jobs.show', 'print-jobs.done', 'print-jobs.failed', 'qz.certificate', 'qz.sign'];

    /** Take payment, split, print bills / receipts, list payments (refunds are the manager's). */
    private const BILLING = ['orders.payments.store', 'orders.split', 'orders.print.bill', 'orders.print.receipt', 'orders.bill', 'payments.index'];

    /** Suppliers, purchases, waste, counts, low stock (approving counts, paying and correcting use are for managers). */
    private const INVENTORY = [
        'suppliers.index', 'suppliers.show', 'suppliers.store', 'suppliers.update', 'low-stock.index',
        'purchases.index', 'purchases.show', 'purchases.create', 'purchases.store', 'purchases.return',
        'waste.index', 'waste.store', 'stock-counts.index', 'stock-counts.show', 'stock-counts.store', 'stock-counts.update',
        'stock-counts.cancel', 'consumptions.pending', 'consumptions.confirm',
    ];

    /** Default expense categories (shared by every branch). */
    private const EXPENSE_CATEGORIES = ['Rent', 'Electricity', 'Gas', 'Water', 'Salaries', 'Maintenance', 'Transport', 'Supplies', 'Other'];

    /** Reservations: calendar, book, confirm / seat / cancel. */
    private const RESERVATIONS = ['reservations.index', 'reservations.store', 'reservations.update', 'reservations.status'];

    /** Deliveries board, riders and their cash (zones are the manager's). */
    private const DELIVERY = ['deliveries.index', 'deliveries.assign', 'deliveries.status', 'riders.index', 'riders.settle'];

    /**
     * Default roles (PLAN §3). Permissions are listed by route name and grow as
     * modules are built; the super admin can change them from the Roles screen.
     */
    private const ROLES = [
        'Manager' => ['Runs their branch(es): menu, stock, staff, shifts, approvals, reports', [
            'admins.index', 'admins.store', 'admins.update', 'activity.index',
            'employees.index', 'employees.store', 'employees.update', 'employees.destroy', 'employees.restore',
            'designations.index', 'customers.index', 'customers.store', 'customers.update',
            'counters.index', 'counters.store', 'counters.update', 'counters.destroy', 'counters.restore',
            'printers.index', 'printers.store', 'printers.update', 'printers.test', 'printers.destroy', 'printers.restore',
            'shift-types.index', 'shift-types.store', 'shift-types.update', 'shift-types.destroy', 'shift-types.restore',
            'bank-accounts.index', 'settings.index', 'settings.branch.receipt', 'settings.branch.printing', 'settings.branch.kitchen',
            'categories.index', 'categories.store', 'categories.update', 'categories.destroy', 'categories.restore',
            'menu-items.index', 'menu-items.store', 'menu-items.update', 'menu-items.destroy', 'menu-items.restore',
            'ready-items.index', 'ready-items.store', 'ready-items.update', 'ready-items.destroy', 'ready-items.restore',
            'modifier-groups.index', 'modifier-groups.store', 'modifier-groups.update', 'modifier-groups.destroy', 'modifier-groups.restore',
            'kitchen-stations.index', 'kitchen-stations.store', 'kitchen-stations.update', 'kitchen-stations.destroy', 'kitchen-stations.restore',
            'raw-materials.index', 'raw-materials.store', 'raw-materials.update', 'raw-materials.destroy', 'raw-materials.restore',
            'raw-material-categories.index', 'raw-material-categories.store', 'raw-material-categories.update', 'raw-material-categories.destroy', 'raw-material-categories.restore',
            'units.index', 'stock.add', 'stock-ledger.index',
            'deals.index', 'deals.store', 'deals.update', 'deals.destroy', 'deals.restore',
            'discounts.index', 'discounts.store', 'discounts.update', 'discounts.destroy', 'discounts.restore',
            'tables.floor', 'tables.status', 'tables.layout',
            'tables.index', 'tables.store', 'tables.update', 'tables.destroy', 'tables.restore',
            'areas.index', 'areas.store', 'areas.update', 'areas.destroy', 'areas.restore',
            'shifts.index', 'shifts.show', 'shifts.report', 'shifts.open', 'shifts.cash', 'shifts.close', 'shifts.reopen',
            'shifts.staff.store', 'shifts.staff.checkout', 'shifts.staff.destroy',
            'pos.index', 'pos.orders.store', 'pos.orders.update', 'pos.orders.discard', 'pos.customers.store',
            'orders.index', 'orders.show', 'orders.discount', 'orders.service-charge', 'orders.items.void', 'orders.cancel',
            'kitchen.index', 'kitchen.tickets.start', 'kitchen.tickets.ready', 'kitchen.tickets.serve', 'kitchen.tickets.recall', 'kitchen.tickets.reprint',
            'waiter.index', 'waiter.table', 'waiter.orders.store', 'waiter.orders.update', 'waiter.orders.serve', 'waiter.orders.bill',
            ...self::INVENTORY, 'suppliers.destroy', 'suppliers.restore', 'suppliers.pay', 'stock-counts.approve', 'consumptions.adjust',
            ...self::DELIVERY, 'delivery-zones.index', 'delivery-zones.store', 'delivery-zones.update', 'delivery-zones.destroy', 'delivery-zones.restore',
            'settings.branch.delivery',
            ...self::PRINT_DEVICE, 'print-jobs.index', 'print-jobs.retry',
            ...self::BILLING, 'orders.payments.refund', 'settings.branch.payments',
            ...self::RESERVATIONS, 'expenses.index', 'expenses.store', 'expenses.void', 'expense-categories.store', 'expense-categories.update',
            'dashboard.stats', 'reports.index', 'reports.sales', 'reports.cash', 'reports.inventory', 'reports.finance', 'reports.export',
        ]],
        'Cashier' => ['POS, billing, payments, own shift, cash in/out, customers', [
            'customers.index', 'customers.store', 'customers.update', 'tables.floor', 'tables.status',
            'shifts.index', 'shifts.show', 'shifts.report', 'shifts.open', 'shifts.cash', 'shifts.close',
            'shifts.staff.store', 'shifts.staff.checkout', 'shifts.staff.destroy',
            'pos.index', 'pos.orders.store', 'pos.orders.update', 'pos.orders.discard', 'pos.customers.store',
            'orders.index', 'orders.show', 'kitchen.tickets.reprint', ...self::PRINT_DEVICE, ...self::BILLING, ...self::DELIVERY,
            ...self::RESERVATIONS, 'expenses.index', 'expenses.store',
        ]],
        'Waiter' => ['Waiter app: tables, dine-in orders, send to kitchen, request bill', [
            'waiter.index', 'waiter.table', 'waiter.orders.store', 'waiter.orders.update', 'waiter.orders.serve', 'waiter.orders.bill',
            'tables.floor', 'tables.status',
        ]],
        'Kitchen' => ['Kitchen display: preparing/ready, confirm raw material used, reprint tickets', [
            'kitchen.index', 'kitchen.tickets.start', 'kitchen.tickets.ready', 'kitchen.tickets.serve', 'kitchen.tickets.recall', 'kitchen.tickets.reprint',
            ...self::PRINT_DEVICE,
        ]],
        'Rider' => ['Assigned deliveries, picked up / delivered, cash to settle', ['rider.index', 'rider.deliveries.status']],
        'Storekeeper' => ['Raw materials, ready item stock, purchases, stock counts, waste', [
            'raw-materials.index', 'raw-materials.store', 'raw-materials.update', 'raw-material-categories.index',
            'ready-items.index', 'units.index', 'stock.add', 'stock-ledger.index', ...self::INVENTORY,
            'reports.index', 'reports.inventory', 'reports.export',
        ]],
    ];

    /** Default designations (PLAN §3): name => [type, default role]. */
    private const DESIGNATIONS = [
        'Manager' => [DesignationType::Manager, 'Manager'],
        'Cashier' => [DesignationType::Cashier, 'Cashier'],
        'Waiter' => [DesignationType::Waiter, 'Waiter'],
        'Chef' => [DesignationType::Kitchen, 'Kitchen'],
        'Rider' => [DesignationType::Rider, 'Rider'],
        'Storekeeper' => [DesignationType::Storekeeper, 'Storekeeper'],
    ];

    public function run(): void
    {
        PermissionCatalog::sync();

        $main = Branch::withTrashed()->firstOrCreate(['code' => 'MAIN'], ['name' => 'Main Branch', 'is_active' => true]);

        Admin::withTrashed()->firstOrCreate(['username' => 'superadmin'], [
            'name' => 'Super Admin',
            'email' => env('SUPERADMIN_EMAIL'),
            'password' => env('SUPERADMIN_PASSWORD', 'password'),
            'pin' => env('SUPERADMIN_PIN', '1234'),
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        foreach (self::EXPENSE_CATEGORIES as $category) {
            ExpenseCategory::withTrashed()->firstOrCreate(['name' => $category]);
        }

        foreach (self::ROLES as $name => [$description, $routes]) {
            $role = Role::withTrashed()->firstOrCreate(['name' => $name], ['description' => $description]);

            if ($role->wasRecentlyCreated && $routes) {
                $ids = Permission::query()->get()
                    ->filter(fn (Permission $p) => array_intersect($p->routes, $routes))
                    ->pluck('id')->all();
                TrashablePivot::sync(RolePermission::class, 'role_id', $role->id, 'permission_id', $ids);
            }
        }

        foreach (self::DESIGNATIONS as $name => [$type, $role]) {
            Designation::withTrashed()->firstOrCreate(['name' => $name], [
                'type' => $type,
                'default_role_id' => Role::query()->where('name', $role)->value('id'),
            ]);
        }

        // A counter and the two usual shifts for the main branch (PLAN §6)
        app(CurrentBranch::class)->actingAs($main, function () {
            CashCounter::withTrashed()->firstOrCreate(['name' => 'Counter 1']);
            ShiftType::withTrashed()->firstOrCreate(['name' => 'Morning'], ['start_time' => '11:00', 'end_time' => '19:00']);
            ShiftType::withTrashed()->firstOrCreate(['name' => 'Night'], ['start_time' => '19:00', 'end_time' => '04:00']);
        });

        if (app()->isLocal()) {
            $this->call([DemoMenuSeeder::class, DemoDealsSeeder::class, DemoFloorSeeder::class]);
        }
    }
}
