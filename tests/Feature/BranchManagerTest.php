<?php

use App\Exceptions\TrashNotAllowed;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Employee;
use Inertia\Testing\AssertableInertia as Assert;

function branchForm(Branch $branch, array $overrides = []): array
{
    return ['code' => $branch->code, 'name' => $branch->name, 'is_active' => true, ...$overrides];
}

it('allocates a manager and gives their login access to the branch', function () {
    loginSuperAdmin();
    $branch = Branch::factory()->create(['name' => 'Gulberg']);
    $elsewhere = Branch::factory()->create();
    $employee = Employee::factory()->forBranch($branch)->create(['name' => 'Farah Noor']);
    $employee->update(['admin_id' => ($admin = Admin::factory()->forBranches($elsewhere)->create())->id]);

    $this->put(route('branches.update', $branch), branchForm($branch, ['manager' => $employee->uuid]))
        ->assertSessionHasNoErrors();

    expect($branch->fresh()->manager_id)->toBe($employee->id)
        ->and($admin->fresh()->branches->pluck('id')->sort()->values()->all())->toBe(collect([$branch->id, $elsewhere->id])->sort()->values()->all())
        ->and(ActivityLog::where('event', 'manager_changed')->first()->properties['attributes']['manager'])->toBe('Farah Noor');

    $response = $this->get(route('branches.index', ['search' => 'Gulberg']))->assertInertia(fn (Assert $page) => $page
        ->where('branches.data.0.manager.name', 'Farah Noor')
        ->where("managers.{$branch->uuid}.0.name", 'Farah Noor'));

    expectNoNumericIds($response->inertiaProps());
});

it('only accepts active employees of that branch as manager', function () {
    loginSuperAdmin();
    $branch = Branch::factory()->create();
    $outsider = Employee::factory()->forBranch(Branch::factory()->create())->create();
    $left = Employee::factory()->forBranch($branch)->create(['status' => 'left']);

    $this->put(route('branches.update', $branch), branchForm($branch, ['manager' => $outsider->uuid]))
        ->assertSessionHasErrors('manager');
    $this->put(route('branches.update', $branch), branchForm($branch, ['manager' => $left->uuid]))
        ->assertSessionHasErrors('manager');
});

it('refuses to trash the employee who manages a branch', function () {
    loginSuperAdmin();
    $branch = Branch::factory()->create(['name' => 'Gulberg']);
    $employee = Employee::factory()->forBranch($branch)->create();
    $branch->update(['manager_id' => $employee->id]);

    expect(fn () => $employee->trash())->toThrow(TrashNotAllowed::class, 'manager of “Gulberg”');
});
