<?php

namespace App\Support\Permissions;

use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * The whole permission catalog. A permission = one tick-box in the role editor,
 * granting one or more route names. Add a group when a module is built, then run
 * `php artisan permissions:sync` (also run by the seeder).
 *
 * A test checks that every route behind the `permission` middleware is listed
 * here (or whitelisted in config/permissions.php) and that every route listed exists.
 */
class PermissionCatalog
{
    /** @return list<array{title: string, permissions: list<array{title: string, routes: list<string>}>}> */
    public static function groups(): array
    {
        return [
            [
                'title' => 'Customers',
                'permissions' => [
                    ['title' => 'View customers', 'routes' => ['customers.index']],
                    ['title' => 'Add customer', 'routes' => ['customers.store']],
                    ['title' => 'Edit customer', 'routes' => ['customers.update']],
                    ['title' => 'Trash customer', 'routes' => ['customers.destroy']],
                    ['title' => 'Restore customer', 'routes' => ['customers.restore']],
                ],
            ],
            [
                'title' => 'Employees',
                'permissions' => [
                    ['title' => 'View employees', 'routes' => ['employees.index']],
                    ['title' => 'Add employee', 'routes' => ['employees.store']],
                    ['title' => 'Edit employee', 'routes' => ['employees.update']],
                    ['title' => 'Trash employee', 'routes' => ['employees.destroy']],
                    ['title' => 'Restore employee', 'routes' => ['employees.restore']],
                ],
            ],
            [
                'title' => 'Designations',
                'permissions' => [
                    ['title' => 'View designations', 'routes' => ['designations.index']],
                    ['title' => 'Add designation', 'routes' => ['designations.store']],
                    ['title' => 'Edit designation', 'routes' => ['designations.update']],
                    ['title' => 'Trash designation', 'routes' => ['designations.destroy']],
                    ['title' => 'Restore designation', 'routes' => ['designations.restore']],
                ],
            ],
            [
                'title' => 'Branches',
                'permissions' => [
                    ['title' => 'View branches', 'routes' => ['branches.index']],
                    ['title' => 'Add branch', 'routes' => ['branches.store']],
                    ['title' => 'Edit branch & allocate manager', 'routes' => ['branches.update']],
                    ['title' => 'Trash branch', 'routes' => ['branches.destroy']],
                    ['title' => 'Restore branch', 'routes' => ['branches.restore']],
                ],
            ],
            [
                'title' => 'Admin Accounts',
                'permissions' => [
                    ['title' => 'View admin accounts', 'routes' => ['admins.index']],
                    ['title' => 'Add admin account', 'routes' => ['admins.store']],
                    ['title' => 'Edit admin account', 'routes' => ['admins.update']],
                    ['title' => 'Trash admin account', 'routes' => ['admins.destroy']],
                    ['title' => 'Restore admin account', 'routes' => ['admins.restore']],
                ],
            ],
            [
                'title' => 'Roles & Permissions',
                'permissions' => [
                    ['title' => 'View roles', 'routes' => ['roles.index']],
                    ['title' => 'Add role', 'routes' => ['roles.store']],
                    ['title' => 'Edit role', 'routes' => ['roles.update']],
                    ['title' => 'Trash role', 'routes' => ['roles.destroy']],
                    ['title' => 'Restore role', 'routes' => ['roles.restore']],
                ],
            ],
            [
                'title' => 'Recycle Bin & Activity',
                'permissions' => [
                    ['title' => 'View recycle bin', 'routes' => ['trash.index']],
                    ['title' => 'Restore from recycle bin', 'routes' => ['trash.restore']],
                    ['title' => 'View activity log', 'routes' => ['activity.index']],
                ],
            ],
        ];
    }

    /** @return list<string> */
    public static function routeNames(): array
    {
        return collect(static::groups())->pluck('permissions')->flatten(1)->pluck('routes')->flatten()->unique()->values()->all();
    }

    /** Upsert the catalog; permissions no longer listed are trashed (and come back if re-added). */
    public static function sync(): void
    {
        DB::transaction(function () {
            $keep = [];

            foreach (static::groups() as $gIndex => $group) {
                $pg = PermissionGroup::withTrashed()->firstOrNew(['title' => $group['title']]);
                $pg->fill(['sort_order' => $gIndex])->save();
                $pg->restoreFromTrash();

                foreach ($group['permissions'] as $pIndex => $item) {
                    $permission = Permission::withTrashed()->firstOrNew([
                        'permission_group_id' => $pg->id,
                        'title' => $item['title'],
                    ]);
                    $permission->fill(['routes' => $item['routes'], 'sort_order' => $pIndex])->save();
                    $permission->restoreFromTrash();
                    $keep[] = $permission->id;
                }
            }

            Permission::query()->whereNotIn('id', $keep)->get()->each->trash('Removed from the permission catalog');
            PermissionGroup::query()->whereDoesntHave('permissions')->get()->each->trash('Removed from the permission catalog');
        });

        Role::forgetAllRouteNames();
    }
}
