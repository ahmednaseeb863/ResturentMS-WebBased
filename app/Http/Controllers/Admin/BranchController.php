<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\BranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        ]);
    }

    public function store(BranchRequest $request): RedirectResponse
    {
        $branch = Branch::create($request->validated());

        return back()->with('success', "Branch “{$branch->name}” added.");
    }

    public function update(BranchRequest $request, Branch $branch): RedirectResponse
    {
        $branch->update($request->validated());

        return back()->with('success', "Branch “{$branch->name}” saved.");
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
