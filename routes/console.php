<?php

use App\Support\Permissions\PermissionCatalog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('permissions:sync', function () {
    PermissionCatalog::sync();
    $this->info('Permission catalog synced ('.count(PermissionCatalog::routeNames()).' routes).');
})->purpose('Sync the permission catalog (App\Support\Permissions\PermissionCatalog) into the database');
