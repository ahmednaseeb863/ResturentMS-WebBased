<?php

use App\Enums\DesignationType;
use App\Exceptions\PermanentDeleteNotAllowed;
use App\Models\Branch;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Role;
use Inertia\Testing\AssertableInertia as Assert;

it('lists designations without ids', function () {
    loginSuperAdmin();
    Designation::factory()->type(DesignationType::Waiter)->create(['name' => 'Waiter']);
    Designation::factory()->create(['name' => 'Old'])->trash();

    $response = $this->get(route('designations.index'))->assertInertia(fn (Assert $page) => $page
        ->component('designations/Index')
        ->has('designations.data', 1)
        ->where('designations.data.0.type', 'waiter')
        ->where('counts.trash', 1));

    expectNoNumericIds($response->inertiaProps());
});

it('adds and edits a designation with a default role', function () {
    loginSuperAdmin();
    $role = Role::factory()->create();

    $this->post(route('designations.store'), ['name' => 'Rider', 'type' => 'rider', 'default_role' => $role->uuid])
        ->assertSessionHasNoErrors();

    $designation = Designation::where('name', 'Rider')->firstOrFail();
    expect($designation->type)->toBe(DesignationType::Rider)
        ->and($designation->default_role_id)->toBe($role->id);

    $this->put(route('designations.update', $designation), ['name' => 'Delivery Rider', 'type' => 'rider', 'default_role' => null])
        ->assertSessionHasNoErrors();

    expect($designation->fresh())->name->toBe('Delivery Rider')->default_role_id->toBeNull();
});

it('checks unique names against trashed designations', function () {
    loginSuperAdmin();
    Designation::factory()->create(['name' => 'Chef'])->trash();

    $this->post(route('designations.store'), ['name' => 'Chef', 'type' => 'kitchen'])
        ->assertSessionHasErrors(['name' => 'A designation in the trash already uses this name — restore it from the Trash tab instead.']);
});

it('refuses to trash a designation that employees still have', function () {
    loginSuperAdmin();
    $designation = Designation::factory()->create();
    Employee::factory()->forBranch(Branch::first())->create(['designation_id' => $designation->id]);

    $this->delete(route('designations.destroy', $designation))->assertSessionHas('error');
    expect($designation->fresh()->isTrashed())->toBeFalse();
});

it('trashes and restores a designation, never hard-deletes', function () {
    loginSuperAdmin();
    $designation = Designation::factory()->create();

    $this->delete(route('designations.destroy', $designation))->assertSessionHas('success');
    expect($designation->fresh()->isTrashed())->toBeTrue();

    $this->post(route('designations.restore', $designation))->assertSessionHas('success');
    expect($designation->fresh()->isTrashed())->toBeFalse()
        ->and(fn () => $designation->delete())->toThrow(PermanentDeleteNotAllowed::class);
});
