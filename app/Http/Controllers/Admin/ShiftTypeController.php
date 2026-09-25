<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShiftTypeRequest;
use App\Http\Resources\ShiftTypeResource;
use App\Models\ShiftType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Shift templates of the current branch (Morning 11:00–19:00, Night 19:00–04:00…). */
class ShiftTypeController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'shift-types.restore');
        $search = trim((string) $request->query('search'));

        $shiftTypes = $this->applyTab(ShiftType::query(), $tab)
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('start_time')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('shift-types/Index', [
            'shiftTypes' => ShiftTypeResource::collection($shiftTypes),
            'filters' => ['tab' => $tab, 'search' => $search],
            'counts' => $this->tabCounts(ShiftType::class),
            'cutoff' => setting('orders.business_day_cutoff'),
        ]);
    }

    public function store(ShiftTypeRequest $request): RedirectResponse
    {
        $shiftType = ShiftType::create($request->shiftTypeData());

        return back()->with('success', "Shift type “{$shiftType->name}” added.");
    }

    public function update(ShiftTypeRequest $request, ShiftType $shiftType): RedirectResponse
    {
        $shiftType->update($request->shiftTypeData());

        return back()->with('success', "Shift type “{$shiftType->name}” saved.");
    }

    public function destroy(Request $request, ShiftType $shiftType): RedirectResponse
    {
        $shiftType->trash($this->trashReason($request));

        return back()->with('success', "Shift type “{$shiftType->name}” moved to trash.");
    }

    public function restore(ShiftType $shiftType): RedirectResponse
    {
        $shiftType->restoreFromTrash();

        return back()->with('success', "Shift type “{$shiftType->name}” restored.");
    }
}
