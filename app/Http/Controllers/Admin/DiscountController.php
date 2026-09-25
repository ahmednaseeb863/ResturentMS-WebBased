<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\DiscountRequest;
use App\Http\Resources\DiscountResource;
use App\Models\Discount;
use App\Support\BusinessDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Predefined discounts of the current branch (PLAN §4.8). */
class DiscountController extends Controller
{
    use ListsWithTrash;

    private const STATUSES = ['active', 'scheduled', 'expired', 'inactive'];

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'discounts.restore');
        $search = trim((string) $request->query('search'));
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : '';
        $today = BusinessDate::for();

        $discounts = $this->applyTab(Discount::query(), $tab)
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($tab === 'active' && $status !== '', fn ($q) => $q->withStatus($status, $today))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('discounts/Index', [
            'discounts' => DiscountResource::collection($discounts),
            'filters' => ['tab' => $tab, 'search' => $search, 'status' => $status],
            'counts' => $this->tabCounts(Discount::class),
            'statusCounts' => Discount::statusCounts($today),
            'types' => DiscountType::options(),
            'scopes' => DiscountScope::options(),
            'approvalAbove' => (float) setting('approvals.pin_discount_above'),
        ]);
    }

    public function store(DiscountRequest $request): RedirectResponse
    {
        $discount = Discount::create($request->discountData());

        return back()->with('success', "Discount “{$discount->name}” added.");
    }

    public function update(DiscountRequest $request, Discount $discount): RedirectResponse
    {
        $discount->update($request->discountData());

        return back()->with('success', "Discount “{$discount->name}” saved.");
    }

    public function destroy(Request $request, Discount $discount): RedirectResponse
    {
        $discount->trash($this->trashReason($request));

        return back()->with('success', "Discount “{$discount->name}” moved to trash.");
    }

    public function restore(Discount $discount): RedirectResponse
    {
        $discount->restoreFromTrash();

        return back()->with('success', "Discount “{$discount->name}” restored.");
    }
}
