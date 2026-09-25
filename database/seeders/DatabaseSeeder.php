<?php

namespace Database\Seeders;

use App\Enums\DesignationType;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\CashCounter;
use App\Models\Designation;
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
        ]],
        'Cashier' => ['POS, billing, payments, own shift, cash in/out, customers', [
            'customers.index', 'customers.store', 'customers.update', 'tables.floor', 'tables.status',
        ]],
        'Waiter' => ['Waiter app: tables, dine-in orders, send to kitchen, request bill', ['tables.floor', 'tables.status']],
        'Kitchen' => ['Kitchen display: preparing/ready, confirm raw material used, reprint tickets', []],
        'Rider' => ['Assigned deliveries, picked up / delivered, cash to settle', []],
        'Storekeeper' => ['Raw materials, ready item stock, purchases, stock counts, waste', [
            'raw-materials.index', 'raw-materials.store', 'raw-materials.update', 'raw-material-categories.index',
            'ready-items.index', 'units.index', 'stock.add', 'stock-ledger.index',
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
