<?php

namespace App\Actions;

use App\Http\Requests\EmployeeRequest;
use App\Models\Admin;
use App\Models\Employee;
use App\Support\Activity;
use App\Support\CurrentBranch;
use Illuminate\Support\Facades\DB;

/**
 * Creates / updates an employee and their login in one transaction.
 *
 * - New employees belong to the current branch (home branch); the code is
 *   generated when left empty.
 * - The login always has access to the home branch, plus the extra branches picked.
 * - An employee who is not Active cannot sign in (their login is switched off).
 */
class SaveEmployee
{
    public function __construct(private CurrentBranch $current) {}

    public function handle(EmployeeRequest $request, ?Employee $employee = null): Employee
    {
        return DB::transaction(function () use ($request, $employee) {
            $data = $request->employeeData();
            $data['code'] = $request->validated('code') ?: ($employee?->code ?? Employee::nextCode($this->current->get()));

            if ($request->hasFile('photo')) {
                $data['photo'] = $request->file('photo')->store('employees', 'public');
            } elseif ($request->boolean('remove_photo')) {
                $data['photo'] = null; // the file is kept, only unlinked
            }

            $employee ??= new Employee;
            $employee->fill($data)->save();

            if ($request->managesLogin()) {
                $this->saveLogin($request, $employee);
            }

            $this->syncLoginWithEmployee($employee);

            return $employee;
        });
    }

    private function saveLogin(EmployeeRequest $request, Employee $employee): void
    {
        $admin = $request->existingLogin();
        $oldRole = $admin?->role?->name;
        $loginData = $request->loginData();

        if ($admin) {
            $admin->update($loginData);

            if ($admin->wasChanged('role_id')) {
                Activity::log('role_changed', $admin, ['old' => ['role' => $oldRole], 'attributes' => ['role' => $admin->fresh('role')->role?->name]]);
            }
        } else {
            $admin = Admin::create($loginData);
            $employee->update(['admin_id' => $admin->id]);
        }

        $admin->syncBranches($this->branchIds($request, $admin, $employee));
        $employee->setRelation('admin', $admin);
    }

    /** Home branch + picked branches; a normal admin cannot remove branches outside their own. */
    private function branchIds(EmployeeRequest $request, Admin $admin, Employee $employee): array
    {
        $ids = [$employee->branch_id, ...$request->loginBranchIds()];
        $actor = $request->actor();

        if (! $actor->is_super_admin) {
            $outside = $admin->branches()->pluck('branches.id')->diff($actor->accessibleBranches()->pluck('id'));
            $ids = [...$ids, ...$outside->all()];
        }

        return array_values(array_unique($ids));
    }

    /** Name follows the employee; a login cannot be active while the employee is not. */
    private function syncLoginWithEmployee(Employee $employee): void
    {
        $admin = $employee->admin()->first();

        if (! $admin) {
            return;
        }

        $admin->name = $employee->name;

        if (! $employee->isActive()) {
            $admin->is_active = false;
        }

        $admin->save();
    }
}
