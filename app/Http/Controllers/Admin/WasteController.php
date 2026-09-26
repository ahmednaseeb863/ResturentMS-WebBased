<?php

namespace App\Http\Controllers\Admin;

use App\Actions\RecordWaste;
use App\Enums\StockAdjustmentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\WasteRequest;
use App\Http\Resources\StockAdjustmentResource;
use App\Models\StockAdjustment;
use App\Support\MenuOptions;
use App\Support\StockOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Waste / damage written off in the current branch (PLAN §4.16). */
class WasteController extends Controller
{
    public function index(Request $request): Response
    {
        $type = StockAdjustmentType::tryFrom((string) $request->query('type'));
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));
        $search = trim((string) $request->query('search'));

        $filtered = fn ($q) => $q
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($from, fn ($q) => $q->whereDate('business_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('business_date', '<=', $to))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('reason', 'like', "%{$search}%")
                ->orWhereHas('items', fn ($i) => $i->where('item_name', 'like', "%{$search}%"))));

        $entries = StockAdjustment::query()->tap($filtered)->with(['items.unit', 'admin'])->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('waste/Index', [
            'entries' => StockAdjustmentResource::collection($entries),
            'filters' => ['type' => $type?->value ?? '', 'from' => $from ?? '', 'to' => $to ?? '', 'search' => $search],
            'total' => (float) StockAdjustment::query()->tap($filtered)->sum('total_cost'),
            'types' => StockAdjustmentType::options(),
            'items' => fn () => StockOptions::items(),
            'units' => fn () => MenuOptions::units(),
        ]);
    }

    public function store(WasteRequest $request, RecordWaste $record): RedirectResponse
    {
        $entry = $record->handle($request->type(), $request->validated('reason'), $request->stockLines(), $request->user('admin'));

        return back()->with('success', "{$entry->code()} — ".money($entry->total_cost).' written off.');
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
