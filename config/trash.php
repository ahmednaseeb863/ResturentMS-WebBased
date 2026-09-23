<?php

use App\Models\Admin;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Role;

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
        'role' => ['model' => Role::class, 'label' => 'Role', 'search' => ['name'], 'route' => 'roles.restore'],
    ],
];
