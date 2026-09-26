<?php

namespace App\Support\Permissions;

use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\Role;
use App\Support\Settings\SettingsRegistry;
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
                'title' => 'POS & Orders',
                'permissions' => [
                    ['title' => 'Use the POS — take, hold and send orders, add customers', 'routes' => ['pos.index', 'pos.orders.store', 'pos.orders.update', 'pos.orders.discard', 'pos.customers.store']],
                    ['title' => 'View orders', 'routes' => ['orders.index', 'orders.show']],
                    ['title' => 'Give discounts (and approve them with a PIN)', 'routes' => ['orders.discount']],
                    ['title' => 'Remove service charge (and approve it with a PIN)', 'routes' => ['orders.service-charge']],
                    ['title' => 'Void sent items (and approve voids with a PIN)', 'routes' => ['orders.items.void']],
                    ['title' => 'Cancel orders (and approve cancels with a PIN)', 'routes' => ['orders.cancel']],
                ],
            ],
            [
                'title' => 'Billing',
                'permissions' => [
                    ['title' => 'Take payments — cash, bank transfer, split payment', 'routes' => ['orders.payments.store']],
                    ['title' => 'Split bills', 'routes' => ['orders.split']],
                    ['title' => 'Print bills and receipts', 'routes' => ['orders.print.bill', 'orders.print.receipt', 'orders.bill']],
                    ['title' => 'Refund payments (and approve refunds with a PIN)', 'routes' => ['orders.payments.refund']],
                    ['title' => 'View payments & refunds', 'routes' => ['payments.index']],
                ],
            ],
            [
                'title' => 'Waiter App',
                'permissions' => [
                    ['title' => 'Use the waiter app — tables, take dine-in orders, send to the kitchen', 'routes' => ['waiter.index', 'waiter.table', 'waiter.orders.store', 'waiter.orders.update']],
                    ['title' => 'Mark ready items served', 'routes' => ['waiter.orders.serve']],
                    ['title' => 'Ask for the bill (prints the pre-bill at the counter)', 'routes' => ['waiter.orders.bill']],
                ],
            ],
            [
                'title' => 'Kitchen',
                'permissions' => [
                    ['title' => 'Kitchen display — start, ready (confirm raw materials), served, recall', 'routes' => ['kitchen.index', 'kitchen.tickets.start', 'kitchen.tickets.ready', 'kitchen.tickets.serve', 'kitchen.tickets.recall']],
                    ['title' => 'Reprint kitchen tickets', 'routes' => ['kitchen.tickets.reprint']],
                ],
            ],
            [
                'title' => 'Printing',
                'permissions' => [
                    ['title' => 'Print from this device (kitchen tickets, void slips, bills, receipts)', 'routes' => ['print-jobs.pending', 'print-jobs.claim', 'print-jobs.show', 'print-jobs.done', 'print-jobs.failed', 'qz.certificate', 'qz.sign']],
                    ['title' => 'View the print queue and print again', 'routes' => ['print-jobs.index', 'print-jobs.retry']],
                ],
            ],
            static::crud('Menu Categories', 'category', 'categories'),
            static::crud('Menu Items', 'menu item', 'menu-items', [
                ['title' => 'Copy menu from another branch', 'routes' => ['menu-items.copy']],
            ]),
            static::crud('Ready Items', 'ready item', 'ready-items'),
            static::crud('Add-on Groups', 'add-on group', 'modifier-groups'),
            static::crud('Kitchen Stations', 'kitchen station', 'kitchen-stations'),
            static::crud('Deals', 'deal', 'deals'),
            static::crud('Discounts', 'discount', 'discounts'),
            [
                'title' => 'Tables & Floor Plan',
                'permissions' => [
                    ['title' => 'View floor plan', 'routes' => ['tables.floor']],
                    ['title' => 'Set table status (available / reserved / cleaning)', 'routes' => ['tables.status']],
                    ['title' => 'Arrange floor plan', 'routes' => ['tables.layout']],
                    ['title' => 'View tables', 'routes' => ['tables.index']],
                    ['title' => 'Add table', 'routes' => ['tables.store']],
                    ['title' => 'Edit table', 'routes' => ['tables.update']],
                    ['title' => 'Trash table', 'routes' => ['tables.destroy']],
                    ['title' => 'Restore table', 'routes' => ['tables.restore']],
                ],
            ],
            static::crud('Dining Areas', 'area', 'areas'),
            static::crud('Raw Materials', 'raw material', 'raw-materials', [
                ['title' => 'Manage raw material categories', 'routes' => [
                    'raw-material-categories.index', 'raw-material-categories.store', 'raw-material-categories.update',
                    'raw-material-categories.destroy', 'raw-material-categories.restore',
                ]],
            ]),
            [
                'title' => 'Stock',
                'permissions' => [
                    ['title' => 'Add stock', 'routes' => ['stock.add']],
                    ['title' => 'View stock ledger', 'routes' => ['stock-ledger.index']],
                ],
            ],
            static::crud('Units', 'unit', 'units'),
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
                'title' => 'Settings',
                'permissions' => [
                    ['title' => 'View branch settings', 'routes' => ['settings.index']],
                    ...array_map(fn (string $group) => [
                        'title' => 'Edit branch settings — '.SettingsRegistry::groups()[$group]['label'],
                        'routes' => ["settings.branch.{$group}"],
                    ], SettingsRegistry::groupKeys()),
                    ['title' => 'View & edit global settings', 'routes' => ['settings.global', 'settings.global.update']],
                ],
            ],
            [
                'title' => 'Shifts & Cash',
                'permissions' => [
                    ['title' => 'View shifts & X / Z reports', 'routes' => ['shifts.index', 'shifts.show', 'shifts.report']],
                    ['title' => 'Open shift', 'routes' => ['shifts.open']],
                    ['title' => 'Cash in / cash out / safe drop', 'routes' => ['shifts.cash']],
                    ['title' => 'Close shift', 'routes' => ['shifts.close']],
                    ['title' => 'Staff on duty (check in / out)', 'routes' => ['shifts.staff.store', 'shifts.staff.checkout', 'shifts.staff.destroy']],
                    ['title' => 'Shift manager — reopen shifts, approve cash differences (PIN), handle other cashiers’ shifts', 'routes' => ['shifts.reopen']],
                ],
            ],
            [
                'title' => 'Cash Counters',
                'permissions' => [
                    ['title' => 'View cash counters', 'routes' => ['counters.index']],
                    ['title' => 'Add cash counter', 'routes' => ['counters.store']],
                    ['title' => 'Edit cash counter', 'routes' => ['counters.update']],
                    ['title' => 'Trash cash counter', 'routes' => ['counters.destroy']],
                    ['title' => 'Restore cash counter', 'routes' => ['counters.restore']],
                ],
            ],
            [
                'title' => 'Printers',
                'permissions' => [
                    ['title' => 'View printers', 'routes' => ['printers.index']],
                    ['title' => 'Add printer', 'routes' => ['printers.store']],
                    ['title' => 'Edit printer', 'routes' => ['printers.update']],
                    ['title' => 'Test print', 'routes' => ['printers.test']],
                    ['title' => 'Trash printer', 'routes' => ['printers.destroy']],
                    ['title' => 'Restore printer', 'routes' => ['printers.restore']],
                ],
            ],
            [
                'title' => 'Shift Types',
                'permissions' => [
                    ['title' => 'View shift types', 'routes' => ['shift-types.index']],
                    ['title' => 'Add shift type', 'routes' => ['shift-types.store']],
                    ['title' => 'Edit shift type', 'routes' => ['shift-types.update']],
                    ['title' => 'Trash shift type', 'routes' => ['shift-types.destroy']],
                    ['title' => 'Restore shift type', 'routes' => ['shift-types.restore']],
                ],
            ],
            [
                'title' => 'Bank Accounts',
                'permissions' => [
                    ['title' => 'View bank accounts', 'routes' => ['bank-accounts.index']],
                    ['title' => 'Add bank account', 'routes' => ['bank-accounts.store']],
                    ['title' => 'Edit bank account', 'routes' => ['bank-accounts.update']],
                    ['title' => 'Trash bank account', 'routes' => ['bank-accounts.destroy']],
                    ['title' => 'Restore bank account', 'routes' => ['bank-accounts.restore']],
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

    /** View / Add / Edit / Trash / Restore for a resource module, plus extra permissions. */
    private static function crud(string $title, string $noun, string $prefix, array $extra = []): array
    {
        return [
            'title' => $title,
            'permissions' => [
                ['title' => 'View '.str($noun)->plural(), 'routes' => ["{$prefix}.index"]],
                ['title' => "Add {$noun}", 'routes' => ["{$prefix}.store"]],
                ['title' => "Edit {$noun}", 'routes' => ["{$prefix}.update"]],
                ['title' => "Trash {$noun}", 'routes' => ["{$prefix}.destroy"]],
                ['title' => "Restore {$noun}", 'routes' => ["{$prefix}.restore"]],
                ...$extra,
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
