<?php

namespace App\Http\Controllers\Admin;

use App\Actions\StockCountFlow;
use App\Enums\StockCountStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StockCountResource;
use App\Models\RawMaterialCategory;
use App\Models\StockCount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock counts of the current branch (PLAN §4.16): start a count sheet, enter what was
 * counted, submit; a manager (`stock-counts.approve`) approves the corrections.
 */
class StockCountController extends Controller
{
    public function index(Request $request): Response
    {
        $status = StockCountStatus::tryFrom((string) $request->query('status'));

        $counts = StockCount::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with(['createdBy', 'approvedBy'])
            ->withCount(['items', 'items as counted_count' => fn ($q) => $q->whereNotNull('counted_qty')])
            ->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('stock-counts/Index', [
            'counts' => StockCountResource::collection($counts),
            'filters' => ['status' => $status?->value ?? ''],
            'statuses' => StockCountStatus::options(),
            'categories' => RawMaterialCategory::query()->orderBy('name')->get()->map(fn ($c) => ['value' => $c->uuid, 'label' => $c->name])->all(),
        ]);
    }

    public function store(Request $request, StockCountFlow $flow): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:all,raw_material,ready_item'],
            'category' => ['nullable', 'uuid', Rule::exists('raw_material_categories', 'uuid')],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $category = ! empty($data['category']) && $data['kind'] === 'raw_material'
            ? RawMaterialCategory::query()->where('uuid', $data['category'])->first()
            : null;

        $count = $flow->start($data['kind'], $category?->id, $data['notes'] ?? null, $request->user('admin'));

        return to_route('stock-counts.show', $count)->with('success', "{$count->code()} started — count the items and enter what you find.");
    }

    public function show(StockCount $stockCount): Response
    {
        $stockCount->load(['items.stockable.stockUnit', 'createdBy', 'submittedBy', 'approvedBy']);

        return Inertia::render('stock-counts/Show', [
            'count' => (new StockCountResource($stockCount))->resolve(),
        ]);
    }

    /** Save counted quantities (`counted` = line uuid => quantity or null). */
    public function update(Request $request, StockCount $stockCount, StockCountFlow $flow): RedirectResponse
    {
        $data = $request->validate([
            'counted' => ['required', 'array', 'max:2000'],
            'counted.*' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'submit' => ['boolean'],
        ]);

        $flow->save($stockCount, array_map(fn ($v) => $v === null ? null : (float) $v, $data['counted']));

        if ($request->boolean('submit')) {
            $flow->submit($stockCount, $request->user('admin'));

            return back()->with('success', "{$stockCount->code()} submitted for approval.");
        }

        return back()->with('success', 'Count saved.');
    }

    public function approve(Request $request, StockCount $stockCount, StockCountFlow $flow): RedirectResponse
    {
        $count = $flow->approve($stockCount, $request->user('admin'));

        return back()->with('success', "{$count->code()} approved — stock corrected (".money($count->variance_value).').');
    }

    public function cancel(StockCount $stockCount, StockCountFlow $flow): RedirectResponse
    {
        $flow->cancel($stockCount);

        return back()->with('success', "{$stockCount->code()} cancelled.");
    }
}
