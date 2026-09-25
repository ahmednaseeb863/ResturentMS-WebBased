<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Support\MenuOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** POS categories of the current branch (menu items + ready items). */
class CategoryController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'categories.restore');
        $search = trim((string) $request->query('search'));

        $categories = $this->applyTab(Category::query(), $tab)
            ->with('kitchenStation')
            ->withCount(['menuItems', 'readyItems'])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($tab === 'active', fn ($q) => $q->ordered())
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('categories/Index', [
            'categories' => CategoryResource::collection($categories),
            'filters' => ['tab' => $tab, 'search' => $search],
            'counts' => $this->tabCounts(Category::class),
            'stations' => MenuOptions::stations(),
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $category = Category::create($request->categoryData());

        return back()->with('success', "Category “{$category->name}” added.");
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $category->update($request->categoryData());

        return back()->with('success', "Category “{$category->name}” saved.");
    }

    public function destroy(Request $request, Category $category): RedirectResponse
    {
        $category->trash($this->trashReason($request));

        return back()->with('success', "Category “{$category->name}” moved to trash.");
    }

    public function restore(Category $category): RedirectResponse
    {
        $category->restoreFromTrash();

        return back()->with('success', "Category “{$category->name}” restored.");
    }
}
