<?php

use App\Models\Admin;
use App\Models\Branch;
use App\Models\Role;

/*
| Modules shown in the Recycle Bin. Add each new Trashable business model here:
|   key => [model, label (singular), columns searched, restore permission route]
| Pivot rows (admin_branch, permission_role…) are not listed — their owner restores them.
*/
return [
    'modules' => [
        'branch' => ['model' => Branch::class, 'label' => 'Branch', 'search' => ['name', 'code'], 'route' => 'branches.restore'],
        'admin' => ['model' => Admin::class, 'label' => 'Admin account', 'search' => ['name', 'username', 'email'], 'route' => 'admins.restore'],
        'role' => ['model' => Role::class, 'label' => 'Role', 'search' => ['name'], 'route' => 'roles.restore'],
    ],
];
