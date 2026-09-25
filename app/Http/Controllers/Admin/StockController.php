<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddStockRequest;
use App\Http\Resources\StockMovementResource;
use App\Models\RawMaterial;
use App\Models\ReadyItem;
use App\Models\StockMovement;
use App\Support\Qty;
use App\Support\StockLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Quick "add stock" and the stock ledger of the current branch. */
class StockController extends Controller
{
    public function ledger(Request $request): Response
    {
        $kind = in_array($request->query('kind'), array_keys(AddStockRequest::KINDS), true) ? $request->query('kind') : '';
        $type = StockMovementType::tryFrom((string) $request->query('type'));
        $search = trim((string) $request->query('search'));
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        // one item's ledger (opened from its row)
        $item = $kind && $request->filled('item')
            ? AddStockRequest::KINDS[$kind]::query()->withTrashed()->with('stockUnit')->where('uuid', $request->query('item'))->first()
            : null;

        $movements = StockMovement::query()
            ->with(['stockable.stockUnit', 'admin'])
            ->when($item, fn ($q) => $q->whereMorphedTo('stockable', $item))
            ->when(! $item && $kind, fn ($q) => $q->where('stockable_type', $kind))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when(! $item && $search !== '', fn ($q) => $q->whereHasMorph(
                'stockable',
                [RawMaterial::class, ReadyItem::class],
                fn (Builder $q) => $q->withTrashed()->where('name', 'like', "%{$search}%"),
            ))
            ->when($from, fn ($q) => $q->whereDate('business_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('business_date', '<=', $to))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('stock-ledger/Index', [
            'movements' => StockMovementResource::collection($movements),
            'filters' => [
                'kind' => $kind,
                'item' => $item?->uuid ?? '',
                'type' => $type?->value ?? '',
                'search' => $search,
                'from' => $from ?? '',
                'to' => $to ?? '',
            ],
            'item' => $item ? [
                'id' => $item->uuid,
                'kind' => $kind,
                'name' => $item->name,
                'unit' => $item->stockUnit?->short_name,
                'current_stock' => $item->current_stock,
                'avg_cost' => $item->avg_cost,
                'is_trashed' => $item->isTrashed(),
            ] : null,
            'types' => StockMovementType::options(),
        ]);
    }

    public function store(AddStockRequest $request, StockLedger $ledger): RedirectResponse
    {
        $item = $request->item();
        $quantity = $request->stockQuantity();

        $ledger->record($item, StockMovementType::StockIn, $quantity, $request->stockUnitCost(), $request->validated('note'));

        $unit = $item->stockUnit->short_name;
        $entered = Qty::format($request->validated('quantity')).' '.$request->unit()->short_name;
        $converted = $request->unit()->id === $item->stock_unit_id ? '' : ' ('.Qty::format($quantity)." {$unit})";

        return back()->with('success', "Added {$entered}{$converted} of “{$item->name}”. Stock now ".Qty::format($item->current_stock)." {$unit}.");
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
