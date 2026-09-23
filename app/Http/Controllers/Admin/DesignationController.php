<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DesignationType;
use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\DesignationRequest;
use App\Http\Resources\DesignationResource;
use App\Models\Designation;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DesignationController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'designations.restore');
        $search = trim((string) $request->query('search'));
        $type = (string) $request->query('type', '');

        $designations = $this->applyTab(Designation::query(), $tab)
            ->with('defaultRole')
            ->withCount('employees') // current branch
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when(DesignationType::tryFrom($type), fn ($q, $t) => $q->where('type', $t))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('designations/Index', [
            'designations' => DesignationResource::collection($designations),
            'filters' => ['tab' => $tab, 'search' => $search, 'type' => $type],
            'counts' => $this->tabCounts(Designation::class),
            'types' => DesignationType::options(),
            'roles' => Role::query()->orderBy('name')->get()->map(fn (Role $r) => ['value' => $r->uuid, 'label' => $r->name]),
        ]);
    }

    public function store(DesignationRequest $request): RedirectResponse
    {
        $designation = Designation::create($request->designationData());

        return back()->with('success', "Designation “{$designation->name}” added.");
    }

    public function update(DesignationRequest $request, Designation $designation): RedirectResponse
    {
        $designation->update($request->designationData());

        return back()->with('success', "Designation “{$designation->name}” saved.");
    }

    public function destroy(Request $request, Designation $designation): RedirectResponse
    {
        $designation->trash($this->trashReason($request));

        return back()->with('success', "Designation “{$designation->name}” moved to trash.");
    }

    public function restore(Designation $designation): RedirectResponse
    {
        $designation->restoreFromTrash();

        return back()->with('success', "Designation “{$designation->name}” restored.");
    }
}
