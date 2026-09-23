<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\BranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Models\Employee;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'branches.restore');
        $search = trim((string) $request->query('search'));
        $status = $request->query('status', 'all');

        $branches = $this->applyTab(Branch::query(), $tab)
            ->with('manager')
            ->withCount('admins')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->when($status !== 'all', fn ($q) => $q->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('branches/Index', [
            'branches' => BranchResource::collection($branches),
            'filters' => ['tab' => $tab, 'search' => $search, 'status' => $status],
            'counts' => $this->tabCounts(Branch::class),
            // manager picker: active employees, grouped by their home branch
            'managers' => fn () => $this->managerOptions(),
        ]);
    }

    public function store(BranchRequest $request): RedirectResponse
    {
        $branch = Branch::create($request->branchData());

        return back()->with('success', "Branch “{$branch->name}” added.");
    }

    public function update(BranchRequest $request, Branch $branch): RedirectResponse
    {
        DB::transaction(function () use ($request, $branch) {
            $oldManager = $branch->manager;
            $branch->update($request->branchData());

            if ($branch->wasChanged('manager_id')) {
                $manager = $request->manager();
                // the allocated manager gets access to the branch automatically
                $manager?->admin?->grantBranch($branch);

                Activity::log('manager_changed', $branch, [
                    'old' => ['manager' => $oldManager?->name],
                    'attributes' => ['manager' => $manager?->name],
                ]);
            }
        });

        return back()->with('success', "Branch “{$branch->name}” saved.");
    }

    /** @return array<string, list<array{id: string, name: string, designation: ?string}>> */
    private function managerOptions(): array
    {
        return Employee::query()->allBranches()
            ->where('status', EmployeeStatus::Active)
            ->with(['branch', 'designation'])
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Employee $e) => $e->branch->uuid)
            ->map(fn ($employees) => $employees->map(fn (Employee $e) => [
                'id' => $e->uuid,
                'name' => $e->name,
                'designation' => $e->designation?->name,
            ])->values())
            ->all();
    }

    public function destroy(Request $request, Branch $branch): RedirectResponse
    {
        $branch->trash($this->trashReason($request, required: true));

        return back()->with('success', "Branch “{$branch->name}” moved to trash.");
    }

    public function restore(Branch $branch): RedirectResponse
    {
        $branch->restoreFromTrash();

        return back()->with('success', "Branch “{$branch->name}” restored.");
    }
}
