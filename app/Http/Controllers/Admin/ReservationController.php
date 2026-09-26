<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ChangeReservationStatus;
use App\Actions\SaveReservation;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\DiningTable;
use App\Models\Reservation;
use App\Support\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reservations of the branch (PLAN §4.17): a month calendar (bookings per day) with the
 * chosen day's bookings next to it, or a searchable list.
 */
class ReservationController extends Controller
{
    public function index(Request $request): Response
    {
        $branchId = app(CurrentBranch::class)->id();
        $now = Reservation::localNow($branchId);

        $view = $request->query('view') === 'list' ? 'list' : 'calendar';
        $day = $this->dateOr($request->query('date'), $now->toDateString());
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? $request->query('month') : substr($day, 0, 7);
        $search = trim((string) $request->query('search'));
        $status = ReservationStatus::tryFrom((string) $request->query('status'))?->value ?? '';

        $props = [
            'filters' => ['view' => $view, 'date' => $day, 'month' => $month, 'search' => $search, 'status' => $status],
            'today' => $now->toDateString(),
            'now' => $now->format('H:i'),
            'tables' => DiningTable::query()->active()->with('area')->orderBy('name')->get()
                ->map(fn (DiningTable $t) => ['value' => $t->uuid, 'label' => $t->name.($t->capacity ? " · {$t->capacity} seats" : '').($t->area ? " · {$t->area->name}" : ''), 'capacity' => $t->capacity])
                ->values(),
            'statuses' => ReservationStatus::options(),
            'defaultMinutes' => (int) setting('orders.reservation_minutes'),
            'counts' => [
                'today' => Reservation::query()->upcoming()->whereDate('reserved_at', $now->toDateString())->count(),
                'upcoming' => Reservation::query()->upcoming()->where('reserved_at', '>=', $now->format('Y-m-d H:i:s'))->count(),
                'guests_today' => (int) Reservation::query()->whereIn('status', [...ReservationStatus::upcoming(), ReservationStatus::Seated])
                    ->whereDate('reserved_at', $now->toDateString())->sum('party_size'),
            ],
        ];

        $with = ['table', 'customer', 'createdBy', 'closedBy'];

        if ($view === 'list') {
            $list = Reservation::query()->with($with)
                ->when($status, fn ($q) => $q->where('status', $status))
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->where('guest_name', 'like', "%{$search}%")
                    ->orWhere('guest_phone', 'like', '%'.preg_replace('/\D/', '', $search).'%')
                    ->orWhere('number', ltrim(preg_replace('/\D/', '', $search), '0') ?: -1)))
                ->when(! $status && $search === '', fn ($q) => $q->where('reserved_at', '>=', $now->startOfDay()->format('Y-m-d H:i:s')))
                ->orderBy('reserved_at')
                ->paginate(25)
                ->withQueryString();

            return Inertia::render('reservations/Index', [...$props, 'list' => ReservationResource::collection($list)]);
        }

        $start = CarbonImmutable::parse("{$month}-01");
        $perDay = Reservation::query()
            ->whereIn('status', [...ReservationStatus::upcoming(), ReservationStatus::Seated])
            ->whereBetween('reserved_at', [$start->format('Y-m-d 00:00:00'), $start->endOfMonth()->format('Y-m-d 23:59:59')])
            ->toBase()
            ->selectRaw('DATE(reserved_at) as day, count(*) as n, sum(party_size) as guests')
            ->groupBy('day')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->day => ['count' => (int) $r->n, 'guests' => (int) $r->guests]]);

        return Inertia::render('reservations/Index', [
            ...$props,
            'month' => ['value' => $month, 'label' => $start->format('F Y'), 'days' => $perDay],
            'day' => ReservationResource::collection(
                Reservation::query()->with($with)->whereDate('reserved_at', $day)->orderBy('reserved_at')->get()
            )->resolve(),
        ]);
    }

    public function store(ReservationRequest $request, SaveReservation $save): RedirectResponse
    {
        $reservation = $save->handle(null, $request->reservationData(), $request->user('admin'));

        return back()->with('success', "{$reservation->code()} booked for {$reservation->guest_name} — {$reservation->reserved_at->format('D j M, H:i')}.");
    }

    public function update(ReservationRequest $request, Reservation $reservation, SaveReservation $save): RedirectResponse
    {
        $save->handle($reservation, $request->reservationData(), $request->user('admin'));

        return back()->with('success', "{$reservation->code()} saved.");
    }

    public function status(Request $request, Reservation $reservation, ChangeReservationStatus $change): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(ChangeReservationStatus::ACTIONS)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $reservation = $change->handle($reservation, $data['action'], $data['reason'] ?? null, $request->user('admin'));

        return back()->with('success', "{$reservation->code()} — {$reservation->status->label()}.");
    }

    private function dateOr(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) ? $value : $default;
    }
}
