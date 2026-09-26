<?php

use App\Models\Admin;
use App\Models\Area;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashCounter;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\DeliveryZone;
use App\Models\Designation;
use App\Models\DiningTable;
use App\Models\Discount;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\KitchenStation;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Printer;
use App\Models\RawMaterial;
use App\Models\RawMaterialCategory;
use App\Models\ReadyItem;
use App\Models\Role;
use App\Models\ShiftType;
use App\Models\Supplier;
use App\Models\Unit;

/*
| Modules shown in the Recycle Bin. Add each new Trashable business model here:
|   key => [model, label (singular), columns searched, restore permission route]
| Pivot / child rows (admin_branch, permission_role, user_addresses…) are not listed —
| their owner restores them.
*/
return [
    'modules' => [
        'branch' => ['model' => Branch::class, 'label' => 'Branch', 'search' => ['name', 'code'], 'route' => 'branches.restore'],
        'admin' => ['model' => Admin::class, 'label' => 'Admin account', 'search' => ['name', 'username', 'email'], 'route' => 'admins.restore'],
        'designation' => ['model' => Designation::class, 'label' => 'Designation', 'search' => ['name'], 'route' => 'designations.restore'],
        'employee' => ['model' => Employee::class, 'label' => 'Employee', 'search' => ['name', 'code', 'phone'], 'route' => 'employees.restore'],
        'customer' => ['model' => Customer::class, 'label' => 'Customer', 'search' => ['name', 'phone', 'email'], 'route' => 'customers.restore'],
        'bank_account' => ['model' => BankAccount::class, 'label' => 'Bank account', 'search' => ['bank_name', 'account_title', 'account_number'], 'route' => 'bank-accounts.restore'],
        'counter' => ['model' => CashCounter::class, 'label' => 'Cash counter', 'search' => ['name'], 'route' => 'counters.restore'],
        'printer' => ['model' => Printer::class, 'label' => 'Printer', 'search' => ['name', 'device_name', 'ip_address'], 'route' => 'printers.restore'],
        'shift_type' => ['model' => ShiftType::class, 'label' => 'Shift type', 'search' => ['name'], 'route' => 'shift-types.restore'],
        'category' => ['model' => Category::class, 'label' => 'Menu category', 'search' => ['name'], 'route' => 'categories.restore'],
        'menu_item' => ['model' => MenuItem::class, 'label' => 'Menu item', 'search' => ['name'], 'route' => 'menu-items.restore'],
        'ready_item' => ['model' => ReadyItem::class, 'label' => 'Ready item', 'search' => ['name', 'code', 'barcode'], 'route' => 'ready-items.restore'],
        'modifier_group' => ['model' => ModifierGroup::class, 'label' => 'Add-on group', 'search' => ['name'], 'route' => 'modifier-groups.restore'],
        'deal' => ['model' => Deal::class, 'label' => 'Deal', 'search' => ['name'], 'route' => 'deals.restore'],
        'discount' => ['model' => Discount::class, 'label' => 'Discount', 'search' => ['name'], 'route' => 'discounts.restore'],
        'table' => ['model' => DiningTable::class, 'label' => 'Table', 'search' => ['name'], 'route' => 'tables.restore'],
        'area' => ['model' => Area::class, 'label' => 'Dining area', 'search' => ['name'], 'route' => 'areas.restore'],
        'kitchen_station' => ['model' => KitchenStation::class, 'label' => 'Kitchen station', 'search' => ['name'], 'route' => 'kitchen-stations.restore'],
        'raw_material' => ['model' => RawMaterial::class, 'label' => 'Raw material', 'search' => ['name', 'code'], 'route' => 'raw-materials.restore'],
        'raw_material_category' => ['model' => RawMaterialCategory::class, 'label' => 'Raw material category', 'search' => ['name'], 'route' => 'raw-material-categories.restore'],
        'unit' => ['model' => Unit::class, 'label' => 'Unit', 'search' => ['name', 'short_name'], 'route' => 'units.restore'],
        'delivery_zone' => ['model' => DeliveryZone::class, 'label' => 'Delivery zone', 'search' => ['name'], 'route' => 'delivery-zones.restore'],
        'supplier' => ['model' => Supplier::class, 'label' => 'Supplier', 'search' => ['name', 'contact_person', 'phone'], 'route' => 'suppliers.restore'],
        'expense_category' => ['model' => ExpenseCategory::class, 'label' => 'Expense category', 'search' => ['name'], 'route' => 'expense-categories.restore'],
        'role' => ['model' => Role::class, 'label' => 'Role', 'search' => ['name'], 'route' => 'roles.restore'],
    ],
];
