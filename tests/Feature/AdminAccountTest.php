<?php

use App\Exceptions\PermanentDeleteNotAllowed;
use App\Models\Admin;
use App\Models\AdminBranch;
use App\Models\Branch;
use App\Models\Role;
use Inertia\Testing\AssertableInertia as Assert;

function accountPayload(array $overrides = []): array
{
    return [
        'name' => 'Sara Malik',
        'username' => 'sara',
        'email' => 'sara@example.com',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
        'pin' => '4321',
        'role' => Role::factory()->create()->uuid,
        'branches' => [Branch::first()->uuid],
        'is_active' => true,
        ...$overrides,
    ];
}

it('creates an account with a role, branches and hashed secrets', function () {
    loginSuperAdmin();

    $this->post(route('admins.store'), accountPayload())->assertSessionHasNoErrors();

    $admin = Admin::where('username', 'sara')->firstOrFail();
    expect($admin->password)->not->toBe('secret123')
        ->and($admin->checkPin('4321'))->toBeTrue()
        ->and($admin->branches()->pluck('uuid')->all())->toBe([Branch::first()->uuid]);
});

it('lists accounts without leaking ids or secrets', function () {
    loginSuperAdmin();
    Admin::factory()->withRole(Role::factory()->create())->forBranches(Branch::first())->create();

    $response = $this->get(route('admins.index'))->assertInertia(fn (Assert $page) => $page
        ->component('admins/Index')
        ->has('admins.data', 2)
        ->missing('admins.data.0.password')
        ->missing('admins.data.0.pin'));

    expectNoNumericIds($response->inertiaProps());
});

it('trashes removed branch access and restores it when given back', function () {
    loginSuperAdmin();
    [$a, $b] = Branch::factory()->count(2)->create();
    $admin = Admin::factory()->withRole(Role::factory()->create())->forBranches($a, $b)->create();
    $payload = fn (array $branches) => [
        'name' => $admin->name, 'username' => $admin->username, 'role' => $admin->role->uuid,
        'branches' => $branches, 'is_active' => true,
    ];

    $this->put(route('admins.update', $admin), $payload([$a->uuid]))->assertSessionHasNoErrors();
    expect($admin->branches()->pluck('uuid')->all())->toBe([$a->uuid])
        ->and(AdminBranch::onlyTrashed()->count())->toBe(1);

    $this->put(route('admins.update', $admin), $payload([$a->uuid, $b->uuid]))->assertSessionHasNoErrors();
    expect($admin->branches()->count())->toBe(2)
        ->and(AdminBranch::withTrashed()->count())->toBe(2); // restored, not duplicated
});

it('stops a normal admin from creating or touching super admins', function () {
    $branch = Branch::factory()->create();
    loginAdminWithRoutes(['admins.index', 'admins.store', 'admins.update', 'admins.destroy'], $branch);
    $super = Admin::factory()->superAdmin()->create();

    $this->post(route('admins.store'), accountPayload(['is_super_admin' => true, 'branches' => [$branch->uuid]]))
        ->assertSessionHasErrors('is_super_admin');
    $this->put(route('admins.update', $super), accountPayload())->assertForbidden();
    $this->delete(route('admins.destroy', $super))->assertForbidden();
});

it('lets a normal admin grant only their own branches', function () {
    [$mine, $other] = Branch::factory()->count(2)->create();
    loginAdminWithRoutes(['admins.store'], $mine);

    $this->post(route('admins.store'), accountPayload(['branches' => [$other->uuid]]))
        ->assertSessionHasErrors('branches');
});

it('hides accounts from other branches', function () {
    [$mine, $other] = Branch::factory()->count(2)->create();
    loginAdminWithRoutes(['admins.index', 'admins.update'], $mine);
    $stranger = Admin::factory()->withRole(Role::factory()->create())->forBranches($other)->create();

    $this->get(route('admins.index'))->assertInertia(fn (Assert $page) => $page->has('admins.data', 1));
    $this->put(route('admins.update', $stranger), accountPayload(['branches' => [$mine->uuid]]))->assertForbidden();
});

it('refuses to trash your own account', function () {
    $me = loginSuperAdmin();

    $this->delete(route('admins.destroy', $me))->assertSessionHas('error');
    expect($me->fresh()->isTrashed())->toBeFalse();
});

it('trashes and restores an account', function () {
    loginSuperAdmin();
    $admin = Admin::factory()->withRole(Role::factory()->create())->forBranches(Branch::first())->create();

    $this->delete(route('admins.destroy', $admin), ['reason' => 'Left the company'])->assertSessionHas('success');
    expect(Admin::find($admin->id))->toBeNull();

    $this->post(route('admins.restore', $admin))->assertSessionHas('success');
    expect(Admin::find($admin->id))->not->toBeNull();
});

it('can never be hard deleted', function () {
    Admin::factory()->create()->delete();
})->throws(PermanentDeleteNotAllowed::class);
