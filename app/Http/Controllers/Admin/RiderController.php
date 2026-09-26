<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SettleRiderCash;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\Employee;
use App\Models\Shift;
use App\Support\CurrentBranch;
use App\Support\DeliveryBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Riders of the branch (PLAN §4.14): who is out, deliveries today, and the COD cash each
 * one holds — settled into the cashier's open shift (a rider settlement in the drawer).
 */
class RiderController extends Controller
{
    public function index(): Response
    {
        $unsettled = Delivery::query()->unsettled()
            ->with(['order.customer', 'rider', 'zone'])
            ->oldest('delivered_at')
            ->get();

        return Inertia::render('riders/Index', [
            'riders' => DeliveryBoard::riders(),
            // riders who left still owe what they hold
            'unsettled' => DeliveryResource::collection($unsettled)->resolve(),
            'cashHeld' => Delivery::cashHeld(app(CurrentBranch::class)->id()),
        ]);
    }

    /** Take the rider's cash into my open shift (`deliveries` = uuids; none = all). */
    public function settle(Request $request, Employee $rider, SettleRiderCash $settle): RedirectResponse
    {
        $data = $request->validate(['deliveries' => ['nullable', 'array', 'max:500'], 'deliveries.*' => ['uuid']]);

        $shift = Shift::openFor($request->user('admin'))
            ?? throw ValidationException::withMessages(['rider' => 'Open your shift first — rider cash goes into your drawer.']);

        $movement = $settle->handle($rider, $shift, $data['deliveries'] ?? null);

        return back()->with('success', money($movement->amount)." from {$rider->name} added to shift {$shift->code()}.");
    }
}
