<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PrinterType;
use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\CashCounterRequest;
use App\Http\Resources\CashCounterResource;
use App\Models\CashCounter;
use App\Models\Printer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Cash counters (drawers) of the current branch. */
class CashCounterController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'counters.restore');
        $search = trim((string) $request->query('search'));

        $counters = $this->applyTab(CashCounter::query(), $tab)
            ->with('receiptPrinter')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('counters/Index', [
            'counters' => CashCounterResource::collection($counters),
            'filters' => ['tab' => $tab, 'search' => $search],
            'counts' => $this->tabCounts(CashCounter::class),
            'printers' => Printer::query()->active()->ofType(PrinterType::Receipt)->orderBy('name')->get()
                ->map(fn (Printer $p) => ['value' => $p->uuid, 'label' => $p->name]),
        ]);
    }

    public function store(CashCounterRequest $request): RedirectResponse
    {
        $counter = CashCounter::create($request->counterData());

        return back()->with('success', "Cash counter “{$counter->name}” added.");
    }

    public function update(CashCounterRequest $request, CashCounter $counter): RedirectResponse
    {
        $counter->update($request->counterData());

        return back()->with('success', "Cash counter “{$counter->name}” saved.");
    }

    public function destroy(Request $request, CashCounter $counter): RedirectResponse
    {
        $counter->trash($this->trashReason($request));

        return back()->with('success', "Cash counter “{$counter->name}” moved to trash.");
    }

    public function restore(CashCounter $counter): RedirectResponse
    {
        $counter->restoreFromTrash();

        return back()->with('success', "Cash counter “{$counter->name}” restored.");
    }
}
