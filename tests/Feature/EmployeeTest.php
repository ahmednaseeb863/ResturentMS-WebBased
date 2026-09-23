<?php

use App\Enums\EmployeeStatus;
use App\Exceptions\PermanentDeleteNotAllowed;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Role;
use App\Support\CurrentBranch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/** Minimal valid employee form. */
function employeeForm(Designation $designation, array $overrides = []): array
{
    return [
        'name' => 'Ali Raza',
        'designation' => $designation->uuid,
        'status' => 'active',
        ...$overrides,
    ];
}

function loginForm(Role $role, array $overrides = []): array
{
    return [
        'enabled' => true,
        'username' => 'ali.raza',
        'password' => 'secret-pass',
        'password_confirmation' => 'secret-pass',
        'pin' => '4321',
        'role' => $role->uuid,
        'is_active' => true,
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->home = Branch::factory()->create(['code' => 'GUL', 'name' => 'Gulberg']);
    $this->other = Branch::factory()->create(['code' => 'DHA', 'name' => 'DHA']);
    $this->designation = Designation::factory()->create();
    $this->atHome = [CurrentBranch::SESSION_KEY => $this->home->id];
});

it('lists only the current branch employees, without ids', function () {
    loginSuperAdmin();
    Employee::factory()->forBranch($this->home)->withLogin()->create(['name' => 'Home Guy']);
    Employee::factory()->forBranch($this->other)->create(['name' => 'Other Guy']);

    $response = $this->withSession($this->atHome)->get(route('employees.index'))->assertInertia(fn (Assert $page) => $page
        ->component('employees/Index')
        ->has('employees.data', 1)
        ->where('employees.data.0.name', 'Home Guy')
        ->whereType('employees.data.0.login.username', 'string')
        ->where('homeBranch.code', 'GUL')
        ->where('nextCode', 'GUL-0001'));

    expectNoNumericIds($response->inertiaProps());
});

it('cannot open another branch employee', function () {
    loginSuperAdmin();
    $employee = Employee::factory()->forBranch($this->other)->create();

    $this->withSession($this->atHome)
        ->put(route('employees.update', $employee), employeeForm($this->designation))
        ->assertNotFound();
});

it('adds an employee with a generated code in the current branch', function () {
    loginSuperAdmin();

    $this->withSession($this->atHome)->post(route('employees.store'), employeeForm($this->designation, ['salary' => '45000']))
        ->assertSessionHasNoErrors();

    $employee = Employee::query()->allBranches()->firstOrFail();
    expect($employee)->code->toBe('GUL-0001')
        ->branch_id->toBe($this->home->id)
        ->admin_id->toBeNull()
        ->salary->toBe('45000.00');
});

it('creates the login with home + extra branch access and the picked role', function () {
    loginSuperAdmin();
    $role = Role::factory()->create();

    $this->withSession($this->atHome)->post(route('employees.store'), employeeForm($this->designation, [
        'login' => loginForm($role, ['branches' => [$this->other->uuid]]),
    ]))->assertSessionHasNoErrors();

    $admin = Admin::where('username', 'ali.raza')->firstOrFail();
    expect($admin)->name->toBe('Ali Raza')->role_id->toBe($role->id)->is_active->toBeTrue()
        ->and($admin->checkPin('4321'))->toBeTrue()
        ->and($admin->branches->pluck('code')->sort()->values()->all())->toBe(['DHA', 'GUL'])
        ->and(Employee::query()->allBranches()->first()->admin_id)->toBe($admin->id);
});

it('does not let an admin without admins.store create logins', function () {
    loginAdminWithRoutes(['employees.index', 'employees.store'], $this->home);

    $this->withSession($this->atHome)->post(route('employees.store'), employeeForm($this->designation, [
        'login' => loginForm(Role::factory()->create()),
    ]))->assertSessionHasErrors('login.enabled');

    expect(Admin::where('username', 'ali.raza')->exists())->toBeFalse();
});

it('only lets a normal admin grant their own branches', function () {
    loginAdminWithRoutes(['employees.store', 'admins.store'], $this->home);

    $this->withSession($this->atHome)->post(route('employees.store'), employeeForm($this->designation, [
        'login' => loginForm(Role::factory()->create(), ['branches' => [$this->other->uuid]]),
    ]))->assertSessionHasErrors('login.branches');
});

it('switches the login off when the employee is not active', function () {
    loginSuperAdmin();
    $employee = Employee::factory()->forBranch($this->home)->withLogin()->create();

    $this->withSession($this->atHome)->put(route('employees.update', $employee), employeeForm($this->designation, [
        'name' => 'Renamed',
        'status' => EmployeeStatus::Left->value,
    ]))->assertSessionHasNoErrors();

    expect($employee->admin->fresh())->is_active->toBeFalse()->name->toBe('Renamed');
});

it('uploads a photo', function () {
    Storage::fake('public');
    loginSuperAdmin();

    $this->withSession($this->atHome)->post(route('employees.store'), employeeForm($this->designation, [
        'photo' => UploadedFile::fake()->image('me.jpg', 200, 200),
    ]))->assertSessionHasNoErrors();

    $employee = Employee::query()->allBranches()->firstOrFail();
    Storage::disk('public')->assertExists($employee->photo);
    expect($employee->photoUrl())->toContain('/storage/employees/');
});

it('validates cnic format and trashed-unique codes', function () {
    loginSuperAdmin();
    Employee::factory()->forBranch($this->home)->create(['code' => 'OLD-1'])->trash();

    $this->withSession($this->atHome)->post(route('employees.store'), employeeForm($this->designation, [
        'cnic' => '12345',
        'code' => 'old-1',
    ]))->assertSessionHasErrors(['cnic', 'code']);
});

it('trashes the employee with their login and restores both', function () {
    $actor = loginSuperAdmin();
    $employee = Employee::factory()->forBranch($this->home)->withLogin()->create();
    $admin = $employee->admin;

    $this->withSession($this->atHome)->delete(route('employees.destroy', $employee), ['reason' => 'Resigned'])
        ->assertSessionHas('success');

    expect($employee->fresh()->isTrashed())->toBeTrue()
        ->and($admin->fresh()->isTrashed())->toBeTrue()
        ->and($admin->fresh()->trash_batch)->toBe($employee->fresh()->trash_batch);

    $this->withSession($this->atHome)->post(route('employees.restore', $employee))->assertSessionHas('success');

    expect($employee->fresh()->isTrashed())->toBeFalse()
        ->and($admin->fresh()->isTrashed())->toBeFalse()
        ->and(fn () => $employee->delete())->toThrow(PermanentDeleteNotAllowed::class)
        ->and($actor->fresh()->isTrashed())->toBeFalse();
});
