<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
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
        'Manager' => ['Runs their branch(es): menu, stock, staff, shifts, approvals, reports', ['admins.index', 'admins.store', 'admins.update', 'activity.index']],
        'Cashier' => ['POS, billing, payments, own shift, cash in/out, customers', []],
        'Waiter' => ['Waiter app: tables, dine-in orders, send to kitchen, request bill', []],
        'Kitchen' => ['Kitchen display: preparing/ready, confirm raw material used, reprint tickets', []],
        'Rider' => ['Assigned deliveries, picked up / delivered, cash to settle', []],
        'Storekeeper' => ['Raw materials, ready item stock, purchases, stock counts, waste', []],
    ];

    public function run(): void
    {
        PermissionCatalog::sync();

        Branch::withTrashed()->firstOrCreate(['code' => 'MAIN'], ['name' => 'Main Branch', 'is_active' => true]);

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
    }
}
