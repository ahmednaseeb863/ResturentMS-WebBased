<?php

use App\Models\Admin;
use App\Models\Branch;
use App\Models\Role;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
 * Project rule (CLAUDE.md §2): numeric ids never leave the server.
 * Fails if any `id` is an integer or any `*_id` key is present in Inertia props.
 */
function expectNoNumericIds(array $props, string $path = 'props'): void
{
    foreach ($props as $key => $value) {
        $here = "{$path}.{$key}";

        if ($key === 'id') {
            expect($value)->not->toBeInt("{$here} is a numeric id");
        }

        if (is_string($key) && str_ends_with($key, '_id')) {
            test()->fail("{$here}: foreign keys must not be sent to the frontend");
        }

        if (is_array($value)) {
            expectNoNumericIds($value, $here);
        }
    }
}

/** Sign in as a super admin (a branch is created if none exists). */
function loginSuperAdmin(): Admin
{
    Branch::query()->exists() || Branch::factory()->create();
    $admin = Admin::factory()->superAdmin()->create();
    test()->actingAs($admin, 'admin');

    return $admin;
}

/**
 * Sign in as a normal admin whose role grants exactly these route names
 * (through the permission catalog), with access to the given branches.
 */
function loginAdminWithRoutes(array $routes, Branch ...$branches): Admin
{
    PermissionCatalog::sync();
    $role = Role::factory()->withRoutes(...$routes)->create();
    $admin = Admin::factory()->withRole($role)->forBranches(...$branches)->create();
    test()->actingAs($admin, 'admin');

    return $admin;
}
