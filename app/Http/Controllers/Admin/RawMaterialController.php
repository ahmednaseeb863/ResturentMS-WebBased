<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SaveStockItem;
use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\RawMaterialRequest;
use App\Http\Resources\RawMaterialResource;
use App\Models\RawMaterial;
use App\Support\MenuOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Raw materials of the current branch with their stock. */
class RawMaterialController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'raw-materials.restore');
        $search = trim((string) $request->query('search'));
        $category = (string) $request->query('category');
        $stock = $request->query('stock') === 'low' ? 'low' : '';

        $materials = $this->applyTab(RawMaterial::query(), $tab)
            ->with('category', 'stockUnit', 'purchaseUnit')
            ->withExists('stockMovements')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")))
            ->when($category !== '', fn ($q) => $q->whereHas('category', fn ($q) => $q->where('uuid', $category)))
            ->when($stock === 'low', fn ($q) => $q->lowStock())
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('raw-materials/Index', [
            'materials' => RawMaterialResource::collection($materials),
            'filters' => ['tab' => $tab, 'search' => $search, 'category' => $category, 'stock' => $stock],
            'counts' => [...$this->tabCounts(RawMaterial::class), 'low' => RawMaterial::query()->lowStock()->count()],
            'categories' => MenuOptions::rawMaterialCategories(),
            'units' => MenuOptions::units(),
        ]);
    }

    public function store(RawMaterialRequest $request, SaveStockItem $save): RedirectResponse
    {
        $material = $save->handle(new RawMaterial, $request->materialData(), $request->opening());

        return back()->with('success', "Raw material “{$material->name}” added.");
    }

    public function update(RawMaterialRequest $request, RawMaterial $rawMaterial, SaveStockItem $save): RedirectResponse
    {
        $save->handle($rawMaterial, $request->materialData());

        return back()->with('success', "Raw material “{$rawMaterial->name}” saved.");
    }

    public function destroy(Request $request, RawMaterial $rawMaterial): RedirectResponse
    {
        $rawMaterial->trash($this->trashReason($request));

        return back()->with('success', "Raw material “{$rawMaterial->name}” moved to trash.");
    }

    public function restore(RawMaterial $rawMaterial): RedirectResponse
    {
        $rawMaterial->restoreFromTrash();

        return back()->with('success', "Raw material “{$rawMaterial->name}” restored.");
    }
}
