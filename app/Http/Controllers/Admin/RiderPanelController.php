<?php

namespace App\Http\Controllers\Admin;

use App\Actions\UpdateDeliveryStatus;
use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\Employee;
use App\Support\BusinessDate;
use App\Support\CurrentBranch;
use App\Support\DeliveryBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rider panel (PLAN §4.14) — the rider's phone: deliveries given to me (call the customer,
 * open the address in Maps, picked up / delivered / failed), today's finished ones and the
 * cash I hold until I settle it at a counter. Refreshes itself (polled).
 */
class RiderPanelController extends Controller
{
    public function index(Request $request): Response
    {
        $me = $this->me($request);
        $mine = fn () => DeliveryBoard::query()->where('rider_id', $me?->id ?? 0)->with(DeliveryBoard::with());

        return Inertia::render('rider/Index', [
            'me' => $me ? ['id' => $me->uuid, 'name' => $me->name, 'rider' => $me->isRider()] : null,
            'view' => $request->query('view') === 'cash' ? 'cash' : 'deliveries',
            'deliveries' => DeliveryResource::collection($mine()->whereIn('status', [DeliveryStatus::Assigned, DeliveryStatus::OutForDelivery, DeliveryStatus::Failed])
                ->oldest('assigned_at')->get())->resolve(),
            'done' => DeliveryResource::collection($mine()->whereIn('status', [DeliveryStatus::Delivered, DeliveryStatus::Returned])
                ->whereHas('order', fn ($q) => $q->where('business_date', BusinessDate::for()))
                ->latest('id')->limit(50)->get())->resolve(),
            'unsettled' => DeliveryResource::collection($mine()->unsettled()->oldest('delivered_at')->get())->resolve(),
        ]);
    }

    /** Picked up / delivered / failed — only my own deliveries. */
    public function status(Request $request, Delivery $delivery, UpdateDeliveryStatus $update): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:out,deliver,fail'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $me = $this->me($request);
        abort_unless($me && $delivery->rider_id === $me->id, 403, 'This delivery is not yours.');

        $delivery = $update->handle($delivery, $data['action'], $request->user('admin'), $data['reason'] ?? null);

        return back()->with('success', DeliveryController::statusMessage($delivery));
    }

    /** The signed-in rider's staff record in this branch. */
    private function me(Request $request): ?Employee
    {
        $employee = $request->user('admin')->employee;

        return $employee && $employee->branch_id === app(CurrentBranch::class)->id() ? $employee : null;
    }
}
