<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SaveEmployee;
use App\Enums\EmployeeStatus;
use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeRequest;
use App\Http\Resources\BranchOptionResource;
use App\Http\Resources\EmployeeResource;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Role;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Employees of the current branch (BelongsToBranch scope) and their logins. */
class EmployeeController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request, CurrentBranch $current): Response
    {
        $tab = $this->listTab($request, 'employees.restore');
        $search = trim((string) $request->query('search'));
        $designation = (string) $request->query('designation', '');
        $status = (string) $request->query('status', '');
        $actor = $request->user('admin');

        $employees = $this->applyTab(Employee::query(), $tab)
            ->with(['designation', 'admin' => fn ($q) => $q->withTrashed()->with(['role', 'branches'])])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('cnic', 'like', "%{$search}%")))
            ->when($designation !== '', fn ($q) => $q->whereRelation('designation', 'uuid', $designation))
            ->when(EmployeeStatus::tryFrom($status), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('employees/Index', [
            'employees' => EmployeeResource::collection($employees),
            'filters' => ['tab' => $tab, 'search' => $search, 'designation' => $designation, 'status' => $status],
            'counts' => $this->tabCounts(Employee::class),
            'statuses' => EmployeeStatus::options(),
            'designations' => Designation::query()->with('defaultRole')->orderBy('name')->get()->map(fn (Designation $d) => [
                'id' => $d->uuid,
                'name' => $d->name,
                'is_active' => $d->is_active,
                'default_role' => $d->defaultRole?->uuid,
            ]),
            'roles' => Role::query()->orderBy('name')->get()->map(fn (Role $r) => ['value' => $r->uuid, 'label' => $r->name]),
            'branches' => BranchOptionResource::collection($actor->accessibleBranches()),
            'homeBranch' => $current->get() ? new BranchOptionResource($current->get()) : null,
            'nextCode' => $current->get() ? Employee::nextCode($current->get()) : null,
        ]);
    }

    public function store(EmployeeRequest $request, SaveEmployee $save): RedirectResponse
    {
        $employee = $save->handle($request);

        return back()->with('success', "Employee “{$employee->name}” added.");
    }

    public function update(EmployeeRequest $request, Employee $employee, SaveEmployee $save): RedirectResponse
    {
        $save->handle($request, $employee);

        return back()->with('success', "Employee “{$employee->name}” saved.");
    }

    public function destroy(Request $request, Employee $employee): RedirectResponse
    {
        $admin = $employee->admin;
        abort_if($admin && ! $request->user('admin')->canManage($admin), 403);

        $employee->trash($this->trashReason($request));

        return back()->with('success', $admin
            ? "Employee “{$employee->name}” and their login moved to trash."
            : "Employee “{$employee->name}” moved to trash.");
    }

    public function restore(Request $request, Employee $employee): RedirectResponse
    {
        $admin = $employee->admin()->withTrashed()->first();
        abort_if($admin && ! $request->user('admin')->canManage($admin), 403);

        $employee->restoreFromTrash();

        return back()->with('success', "Employee “{$employee->name}” restored.");
    }
}
