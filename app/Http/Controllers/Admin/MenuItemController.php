<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CopyMenu;
use App\Actions\SaveMenuItem;
use App\Enums\OrderType;
use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\MenuItemRequest;
use App\Http\Resources\MenuItemResource;
use App\Models\Branch;
use App\Models\MenuItem;
use App\Support\CurrentBranch;
use App\Support\MenuOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Menu items of the current branch with recipes, sizes and add-ons; copy a menu from another branch. */
class MenuItemController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'menu-items.restore');
        $search = trim((string) $request->query('search'));
        $category = (string) $request->query('category');
        $status = in_array($request->query('status'), ['active', 'inactive', 'sold_out'], true) ? $request->query('status') : '';

        $items = $this->applyTab(MenuItem::query(), $tab)
            ->with([
                'category.kitchenStation', 'kitchenStation', 'modifierGroups',
                'recipeItems.rawMaterial.stockUnit', 'recipeItems.unit',
                'variants.recipeItems.rawMaterial.stockUnit', 'variants.recipeItems.unit',
            ])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($category !== '', fn ($q) => $q->whereHas('category', fn ($q) => $q->where('uuid', $category)))
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($status === 'sold_out', fn ($q) => $q->where('is_sold_out', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $admin = $request->user('admin');
        $current = app(CurrentBranch::class)->id();

        return Inertia::render('menu-items/Index', [
            'items' => MenuItemResource::collection($items),
            'filters' => ['tab' => $tab, 'search' => $search, 'category' => $category, 'status' => $status],
            'counts' => $this->tabCounts(MenuItem::class),
            'categories' => MenuOptions::categories(),
            'stations' => MenuOptions::stations(),
            'rawMaterials' => MenuOptions::rawMaterials(),
            'units' => MenuOptions::units(),
            'modifierGroups' => MenuOptions::modifierGroups(),
            'orderTypes' => OrderType::options(),
            'copyBranches' => $admin->canRoute('menu-items.copy')
                ? $admin->accessibleBranches()->where('id', '!=', $current)
                    ->map(fn (Branch $b) => ['value' => $b->uuid, 'label' => $b->name])->values()
                : [],
        ]);
    }

    public function store(MenuItemRequest $request, SaveMenuItem $save): RedirectResponse
    {
        $item = $save->handle($request);

        return back()->with('success', "Menu item “{$item->name}” added.");
    }

    public function update(MenuItemRequest $request, MenuItem $menuItem, SaveMenuItem $save): RedirectResponse
    {
        $save->handle($request, $menuItem);

        return back()->with('success', "Menu item “{$menuItem->name}” saved.");
    }

    public function destroy(Request $request, MenuItem $menuItem): RedirectResponse
    {
        $menuItem->trash($this->trashReason($request));

        return back()->with('success', "Menu item “{$menuItem->name}” moved to trash.");
    }

    public function restore(MenuItem $menuItem): RedirectResponse
    {
        $menuItem->restoreFromTrash();

        return back()->with('success', "Menu item “{$menuItem->name}” restored.");
    }

    /** Copy another branch's menu into the current branch (without stock). */
    public function copy(Request $request, CopyMenu $copy): RedirectResponse
    {
        $request->validate(['from' => ['required', 'uuid']]);

        $target = app(CurrentBranch::class)->get();
        $from = Branch::query()->where('uuid', $request->input('from'))->first();

        if (! $from || $from->id === $target->id || ! $request->user('admin')->canAccessBranch($from)) {
            return back()->withErrors(['from' => 'Pick another branch you can access.']);
        }

        $counts = $copy->handle($from, $target);

        $made = collect($counts)->except(['reused', 'skipped'])->filter()->map(fn ($n, $what) => "{$n} {$what}");
        $summary = $made->isEmpty() ? 'Nothing new to copy' : 'Copied '.$made->join(', ');
        $extra = collect(['reused' => 'already here', 'skipped' => 'items skipped (already on this menu)'])
            ->filter(fn ($text, $key) => $counts[$key] > 0)
            ->map(fn ($text, $key) => "{$counts[$key]} {$text}")
            ->join('; ');

        return back()->with('success', "{$summary} from “{$from->name}”".($extra ? " — {$extra}." : '.'));
    }
}
