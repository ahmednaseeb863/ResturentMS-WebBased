<?php

use App\Exceptions\PermanentDeleteNotAllowed;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Support\Permissions\PermissionCatalog;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => PermissionCatalog::sync());

function permissionUuidsFor(string ...$routes): array
{
    return Permission::all()->filter(fn ($p) => array_intersect($p->routes, $routes))->pluck('uuid')->values()->all();
}

it('lists roles with the permission catalog, uuids only', function () {
    loginSuperAdmin();
    Role::factory()->withRoutes('branches.index')->create();

    $response = $this->get(route('roles.index'))->assertInertia(fn (Assert $page) => $page
        ->component('roles/Index')
        ->has('roles.data', 1)
        ->has('roles.data.0.permissions', 1)
        ->has('groups', count(PermissionCatalog::groups())));

    expectNoNumericIds($response->inertiaProps());
});

it('creates a role with permissions and logs the grants', function () {
    loginSuperAdmin();

    $this->post(route('roles.store'), [
        'name' => 'Cashier',
        'permissions' => permissionUuidsFor('branches.index', 'admins.index'),
    ])->assertSessionHasNoErrors();

    $role = Role::where('name', 'Cashier')->firstOrFail();
    expect($role->allowedRouteNames())->toEqualCanonicalizing(['branches.index', 'admins.index'])
        ->and(ActivityLog::where('event', 'permissions')->first()->properties['granted'])->toHaveCount(2);
});

it('revokes by trashing the pivot row and forgets the cached routes', function () {
    loginSuperAdmin();
    $role = Role::factory()->withRoutes('branches.index', 'admins.index')->create();
    expect($role->allowedRouteNames())->toHaveCount(2); // warm the cache

    $this->put(route('roles.update', $role), [
        'name' => $role->name,
        'permissions' => permissionUuidsFor('branches.index'),
    ])->assertSessionHasNoErrors();

    expect($role->fresh()->allowedRouteNames())->toBe(['branches.index'])
        ->and(RolePermission::onlyTrashed()->count())->toBe(1);
});

it('rejects unknown or trashed permissions', function () {
    loginSuperAdmin();
    $gone = Permission::first();
    $gone->trash();

    $this->post(route('roles.store'), ['name' => 'X', 'permissions' => [$gone->uuid]])
        ->assertSessionHasErrors('permissions.0');
});

it('refuses to trash a role that is still assigned', function () {
    loginSuperAdmin();
    $role = Role::factory()->create();
    Admin::factory()->withRole($role)->create();

    $this->delete(route('roles.destroy', $role))->assertSessionHas('error');
    expect($role->fresh()->isTrashed())->toBeFalse();
});

it('trashes and restores an unused role', function () {
    loginSuperAdmin();
    $role = Role::factory()->create();

    $this->delete(route('roles.destroy', $role))->assertSessionHas('success');
    $this->get(route('roles.index', ['tab' => 'trash']))->assertInertia(fn (Assert $page) => $page->has('roles.data', 1));

    $this->post(route('roles.restore', $role))->assertSessionHas('success');
    expect($role->fresh()->isTrashed())->toBeFalse();
});

it('can never be hard deleted', function () {
    Role::factory()->create()->delete();
})->throws(PermanentDeleteNotAllowed::class);
