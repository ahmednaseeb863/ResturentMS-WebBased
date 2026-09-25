<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SaveStockItem;
use App\Enums\OrderType;
use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReadyItemRequest;
use App\Http\Resources\ReadyItemResource;
use App\Models\ReadyItem;
use App\Support\MenuOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Ready items (sold as-is, stock kept) of the current branch. */
class ReadyItemController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'ready-items.restore');
        $search = trim((string) $request->query('search'));
        $category = (string) $request->query('category');
        $stock = $request->query('stock') === 'low' ? 'low' : '';

        $items = $this->applyTab(ReadyItem::query(), $tab)
            ->with('category', 'kitchenStation', 'stockUnit', 'purchaseUnit')
            ->withExists('stockMovements')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('barcode', $search)))
            ->when($category !== '', fn ($q) => $q->whereHas('category', fn ($q) => $q->where('uuid', $category)))
            ->when($stock === 'low', fn ($q) => $q->lowStock())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('ready-items/Index', [
            'items' => ReadyItemResource::collection($items),
            'filters' => ['tab' => $tab, 'search' => $search, 'category' => $category, 'stock' => $stock],
            'counts' => [...$this->tabCounts(ReadyItem::class), 'low' => ReadyItem::query()->lowStock()->count()],
            'categories' => MenuOptions::categories(),
            'stations' => MenuOptions::stations(),
            'units' => MenuOptions::units(),
            'orderTypes' => OrderType::options(),
        ]);
    }

    public function store(ReadyItemRequest $request, SaveStockItem $save): RedirectResponse
    {
        $item = $save->handle(new ReadyItem, $request->itemData(), $request->opening());

        return back()->with('success', "Ready item “{$item->name}” added.");
    }

    public function update(ReadyItemRequest $request, ReadyItem $readyItem, SaveStockItem $save): RedirectResponse
    {
        $save->handle($readyItem, $request->itemData());

        return back()->with('success', "Ready item “{$readyItem->name}” saved.");
    }

    public function destroy(Request $request, ReadyItem $readyItem): RedirectResponse
    {
        $readyItem->trash($this->trashReason($request));

        return back()->with('success', "Ready item “{$readyItem->name}” moved to trash.");
    }

    public function restore(ReadyItem $readyItem): RedirectResponse
    {
        $readyItem->restoreFromTrash();

        return back()->with('success', "Ready item “{$readyItem->name}” restored.");
    }
}
