<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\RawMaterialCategoryRequest;
use App\Http\Resources\RawMaterialCategoryResource;
use App\Models\RawMaterialCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Raw material categories of the current branch (Meat, Dairy, Packaging…). */
class RawMaterialCategoryController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'raw-material-categories.restore');
        $search = trim((string) $request->query('search'));

        $categories = $this->applyTab(RawMaterialCategory::query(), $tab)
            ->withCount('rawMaterials')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('raw-material-categories/Index', [
            'categories' => RawMaterialCategoryResource::collection($categories),
            'filters' => ['tab' => $tab, 'search' => $search],
            'counts' => $this->tabCounts(RawMaterialCategory::class),
        ]);
    }

    public function store(RawMaterialCategoryRequest $request): RedirectResponse
    {
        $category = RawMaterialCategory::create($request->validated());

        return back()->with('success', "Category “{$category->name}” added.");
    }

    public function update(RawMaterialCategoryRequest $request, RawMaterialCategory $rawMaterialCategory): RedirectResponse
    {
        $rawMaterialCategory->update($request->validated());

        return back()->with('success', "Category “{$rawMaterialCategory->name}” saved.");
    }

    public function destroy(Request $request, RawMaterialCategory $rawMaterialCategory): RedirectResponse
    {
        $rawMaterialCategory->trash($this->trashReason($request));

        return back()->with('success', "Category “{$rawMaterialCategory->name}” moved to trash.");
    }

    public function restore(RawMaterialCategory $rawMaterialCategory): RedirectResponse
    {
        $rawMaterialCategory->restoreFromTrash();

        return back()->with('success', "Category “{$rawMaterialCategory->name}” restored.");
    }
}
