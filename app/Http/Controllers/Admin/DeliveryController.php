<?php

namespace App\Http\Controllers\Admin;

use App\Actions\AssignRider;
use App\Actions\UpdateDeliveryStatus;
use App\Enums\DeliveryStatus;
use App\Enums\DesignationType;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\Employee;
use App\Support\BusinessDate;
use App\Support\CurrentBranch;
use App\Support\DeliveryBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deliveries board (PLAN §4.14): the deliveries to send out with their kitchen status,
 * give them to riders, mark them out / delivered / failed / returned for a rider without
 * a phone, and the day's finished deliveries. Refreshes itself (polled).
 */
class DeliveryController extends Controller
{
    public function index(Request $request): Response
    {
        $view = $request->query('view') === 'done' ? 'done' : 'active';
        $date = $this->date($request->query('date')) ?? BusinessDate::for();
        $search = trim((string) $request->query('search'));

        $done = fn ($q) => $q->whereIn('status', [DeliveryStatus::Delivered, DeliveryStatus::Returned])
            ->whereHas('order', fn ($o) => $o->whereDate('business_date', $date));

        $deliveries = DeliveryBoard::query()
            ->when($view === 'active', fn ($q) => $q->active()->oldest('id'), fn ($q) => $q->tap($done)->latest('id'))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('address', 'like', "%{$search}%")
                ->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $search).'%')
                ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")))))
            ->with(DeliveryBoard::with())
            ->limit(200)
            ->get();

        $counts = DeliveryBoard::query()->active()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return Inertia::render('deliveries/Index', [
            'deliveries' => DeliveryResource::collection($deliveries)->resolve(),
            'riders' => DeliveryBoard::riders(),
            'filters' => ['view' => $view, 'date' => $date, 'search' => $search],
            'counts' => [
                ...collect(DeliveryStatus::active())->mapWithKeys(fn ($s) => [$s->value => (int) ($counts[$s->value] ?? 0)]),
                'done' => DeliveryBoard::query()->tap($done)->count(),
            ],
            'cashHeld' => Delivery::cashHeld(app(CurrentBranch::class)->id()),
            'businessDate' => BusinessDate::for(),
        ]);
    }

    /** Give the delivery to a rider (none = unassigned). */
    public function assign(Request $request, Delivery $delivery, AssignRider $assign): RedirectResponse
    {
        $data = $request->validate(['rider' => ['nullable', 'uuid']]);
        $rider = null;
        if (! empty($data['rider'])) {
            $rider = Employee::query()->ofType(DesignationType::Rider)->where('uuid', $data['rider'])->first()
                ?? throw ValidationException::withMessages(['rider' => 'Pick an active rider of this branch.']);
        }

        $delivery = $assign->handle($delivery, $rider, $request->user('admin'));
        $code = $delivery->order->code();

        return back()->with('success', $rider ? "{$code} given to {$rider->name}." : "{$code} is unassigned.");
    }

    /** Out / delivered / failed / returned, done at the counter. */
    public function status(Request $request, Delivery $delivery, UpdateDeliveryStatus $update): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:'.implode(',', UpdateDeliveryStatus::ACTIONS)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $delivery = $update->handle($delivery, $data['action'], $request->user('admin'), $data['reason'] ?? null);

        return back()->with('success', static::statusMessage($delivery));
    }

    public static function statusMessage(Delivery $delivery): string
    {
        $delivery->loadMissing('order', 'rider');
        $code = $delivery->order->code();

        return match ($delivery->status) {
            DeliveryStatus::OutForDelivery => "{$code} is out for delivery".($delivery->rider ? " with {$delivery->rider->name}" : '').'.',
            DeliveryStatus::Delivered => (float) $delivery->cash_collected > 0
                ? "{$code} delivered — ".money($delivery->cash_collected).' cash with '.($delivery->rider?->name ?? 'the rider').'.'
                : "{$code} delivered.",
            DeliveryStatus::Failed => "{$code} marked failed.",
            DeliveryStatus::Returned => "{$code} is back — give it to a rider again or cancel the order.",
            default => "{$code} updated.",
        };
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
