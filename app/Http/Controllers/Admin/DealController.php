<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SaveDeal;
use App\Enums\OrderType;
use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\DealRequest;
use App\Http\Resources\DealResource;
use App\Models\Deal;
use App\Support\BusinessDate;
use App\Support\MenuOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Deals / combos of the current branch (PLAN §4.8). */
class DealController extends Controller
{
    use ListsWithTrash;

    private const STATUSES = ['active', 'scheduled', 'expired', 'inactive'];

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'deals.restore');
        $search = trim((string) $request->query('search'));
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : '';
        $today = BusinessDate::for();

        $deals = $this->applyTab(Deal::query(), $tab)
            ->with(DealResource::eagerLoads())
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($tab === 'active' && $status !== '', fn ($q) => $q->withStatus($status, $today))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('deals/Index', [
            'deals' => DealResource::collection($deals),
            'filters' => ['tab' => $tab, 'search' => $search, 'status' => $status],
            'counts' => $this->tabCounts(Deal::class),
            'statusCounts' => Deal::statusCounts($today),
            'sellables' => MenuOptions::sellables(),
            'orderTypes' => OrderType::options(),
            'days' => collect(Deal::DAYS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function store(DealRequest $request, SaveDeal $save): RedirectResponse
    {
        $deal = $save->handle($request);

        return back()->with('success', "Deal “{$deal->name}” added.");
    }

    public function update(DealRequest $request, Deal $deal, SaveDeal $save): RedirectResponse
    {
        $save->handle($request, $deal);

        return back()->with('success', "Deal “{$deal->name}” saved.");
    }

    public function destroy(Request $request, Deal $deal): RedirectResponse
    {
        $deal->trash($this->trashReason($request));

        return back()->with('success', "Deal “{$deal->name}” moved to trash.");
    }

    public function restore(Deal $deal): RedirectResponse
    {
        $deal->restoreFromTrash();

        return back()->with('success', "Deal “{$deal->name}” restored.");
    }
}
