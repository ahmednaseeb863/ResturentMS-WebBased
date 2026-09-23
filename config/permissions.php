<?php

return [
    /*
    | Route names every signed-in admin may use, whatever their role.
    | Everything else inside the `permission` middleware group must be granted
    | through a role (see App\Support\Permissions\PermissionCatalog).
    */
    'whitelist' => [
        'dashboard',
        'logout',
        'branch.switch',
    ],
];
