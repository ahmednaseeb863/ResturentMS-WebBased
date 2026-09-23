<?php

use App\Models\Admin;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

it('covers every protected route in the catalog or the whitelist', function () {
    $known = [...PermissionCatalog::routeNames(), ...config('permissions.whitelist')];

    $protected = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('permission', $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->getName());

    expect($protected->filter()->count())->toBe($protected->count(), 'every protected route needs a name')
        ->and($protected->diff($known)->values()->all())->toBe([]);
});

it('only lists routes that exist', function () {
    $missing = collect(PermissionCatalog::routeNames())->reject(fn ($name) => Route::has($name));

    expect($missing->values()->all())->toBe([]);
});

it('syncs the catalog and trashes permissions that were removed from it', function () {
    PermissionCatalog::sync();
    $count = Permission::count();

    $stale = Permission::create([
        'permission_group_id' => Permission::first()->permission_group_id,
        'title' => 'Old permission',
        'routes' => ['old.route'],
    ]);

    PermissionCatalog::sync();

    expect(Permission::count())->toBe($count)
        ->and($stale->fresh()->isTrashed())->toBeTrue();
});

it('allows a route only when the role grants it', function () {
    loginAdminWithRoutes(['branches.index']);

    $this->get(route('branches.index'))->assertOk();
    $this->get(route('admins.index'))->assertForbidden();
    $this->post(route('branches.store'), ['code' => 'X', 'name' => 'X'])->assertForbidden();
});

it('always allows whitelisted routes', function () {
    loginAdminWithRoutes([]);

    $this->get(route('dashboard'))->assertOk();
});

it('lets a super admin through everything', function () {
    loginSuperAdmin();

    $this->get(route('roles.index'))->assertOk();
    $this->get(route('trash.index'))->assertOk();
});

it('shares the granted route names with the page', function () {
    loginAdminWithRoutes(['branches.index', 'branches.store']);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.permissions.all', false)
        ->where('auth.permissions.routes', fn ($routes) => collect($routes)
            ->intersect(['dashboard', 'branches.index', 'branches.store'])->count() === 3
            && ! collect($routes)->contains('admins.index')));
});

it('applies a permission change on the next request', function () {
    $admin = loginAdminWithRoutes(['branches.index']);
    $this->get(route('admins.index'))->assertForbidden();

    // a super admin grants "View admin accounts" to the role
    $role = $admin->role;
    $grant = Permission::all()->filter(fn ($p) => in_array('admins.index', $p->routes))->pluck('uuid');
    $keep = $role->permissions()->pluck('permissions.uuid');

    $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin')
        ->put(route('roles.update', $role), ['name' => $role->name, 'permissions' => [...$keep, ...$grant]])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin->fresh(), 'admin')->get(route('admins.index'))->assertOk();
});

it('keeps other branches out of reach of a branch admin', function () {
    [$mine, $other] = Branch::factory()->count(2)->create();
    loginAdminWithRoutes([], $mine);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('context.branch.id', $mine->uuid)
        ->has('context.branches', 1));
    $this->post(route('branch.switch'), ['branch' => $other->uuid])->assertForbidden();
});

it('never lets an admin edit the role they hold', function () {
    $admin = loginAdminWithRoutes(['roles.index', 'roles.update']);
    $role = Role::find($admin->role_id);

    $this->put(route('roles.update', $role), ['name' => 'Boss', 'permissions' => []])->assertForbidden();
});
