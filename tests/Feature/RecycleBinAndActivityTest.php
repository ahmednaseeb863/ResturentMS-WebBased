<?php

use App\Exceptions\PermanentDeleteNotAllowed;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Role;
use Inertia\Testing\AssertableInertia as Assert;

it('lists trashed records from every module', function () {
    loginSuperAdmin();
    Branch::factory()->create(['name' => 'Old Town'])->trash('Closed');
    Role::factory()->create(['name' => 'Temp'])->trash();

    $response = $this->get(route('trash.index'))->assertInertia(fn (Assert $page) => $page
        ->component('trash/Index')
        ->has('items.data', 2)
        ->where('items.meta.total', 2));

    expectNoNumericIds($response->inertiaProps());

    $this->get(route('trash.index', ['module' => 'branch']))->assertInertia(fn (Assert $page) => $page
        ->has('items.data', 1)
        ->where('items.data.0.name', 'Old Town')
        ->where('items.data.0.delete_reason', 'Closed'));
});

it('restores from the recycle bin', function () {
    loginSuperAdmin();
    $role = Role::factory()->create();
    $role->trash();

    $this->post(route('trash.restore', ['module' => 'role', 'uuid' => $role->uuid]))->assertSessionHas('success');
    expect($role->fresh()->isTrashed())->toBeFalse();

    $this->post(route('trash.restore', ['module' => 'nope', 'uuid' => $role->uuid]))->assertNotFound();
});

it('does not let a normal admin restore a super admin from the bin', function () {
    loginAdminWithRoutes(['trash.index', 'trash.restore']);
    Admin::factory()->superAdmin()->create(); // so this one is not the last super admin
    $super = Admin::factory()->superAdmin()->create();
    $super->trash();

    $this->post(route('trash.restore', ['module' => 'admin', 'uuid' => $super->uuid]))->assertForbidden();
});

it('shows the activity log without ids', function () {
    loginSuperAdmin();
    $branch = Branch::factory()->create(['name' => 'Gulberg']);
    $branch->update(['name' => 'Gulberg III']);

    $response = $this->get(route('activity.index', ['search' => 'Gulberg']))->assertInertia(fn (Assert $page) => $page
        ->component('activity/Index')
        ->where('logs.data.0.event', 'updated')
        ->where('logs.data.0.properties.old.name', 'Gulberg')
        ->where('logs.data.0.properties.attributes.name', 'Gulberg III'));

    expectNoNumericIds($response->inertiaProps());
});

it('keeps the activity log append-only', function () {
    loginSuperAdmin();
    $log = ActivityLog::firstOrFail();

    expect(fn () => $log->update(['event' => 'x']))->toThrow(PermanentDeleteNotAllowed::class)
        ->and(fn () => $log->delete())->toThrow(PermanentDeleteNotAllowed::class);
})->beforeEach(fn () => Branch::factory()->create());
