<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SaveModifierGroup;
use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\ModifierGroupRequest;
use App\Http\Resources\ModifierGroupResource;
use App\Models\ModifierGroup;
use App\Support\MenuOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Add-on (modifier) groups of the current branch. */
class ModifierGroupController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'modifier-groups.restore');
        $search = trim((string) $request->query('search'));

        $groups = $this->applyTab(ModifierGroup::query(), $tab)
            ->with(['modifiers.recipeItems.rawMaterial.stockUnit', 'modifiers.recipeItems.unit'])
            ->withCount('menuItems')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('modifier-groups/Index', [
            'groups' => ModifierGroupResource::collection($groups),
            'filters' => ['tab' => $tab, 'search' => $search],
            'counts' => $this->tabCounts(ModifierGroup::class),
            'rawMaterials' => MenuOptions::rawMaterials(),
            'units' => MenuOptions::units(),
        ]);
    }

    public function store(ModifierGroupRequest $request, SaveModifierGroup $save): RedirectResponse
    {
        $group = $save->handle($request);

        return back()->with('success', "Add-on group “{$group->name}” added.");
    }

    public function update(ModifierGroupRequest $request, ModifierGroup $modifierGroup, SaveModifierGroup $save): RedirectResponse
    {
        $save->handle($request, $modifierGroup);

        return back()->with('success', "Add-on group “{$modifierGroup->name}” saved.");
    }

    public function destroy(Request $request, ModifierGroup $modifierGroup): RedirectResponse
    {
        $modifierGroup->trash($this->trashReason($request));

        return back()->with('success', "Add-on group “{$modifierGroup->name}” moved to trash.");
    }

    public function restore(ModifierGroup $modifierGroup): RedirectResponse
    {
        $modifierGroup->restoreFromTrash();

        return back()->with('success', "Add-on group “{$modifierGroup->name}” restored.");
    }
}
