<?php

namespace App\Http\Controllers\Admin;

use App\Actions\MarkKitchenReady;
use App\Actions\MoveKitchenTicket;
use App\Enums\KitchenStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\KitchenReadyRequest;
use App\Http\Resources\KitchenTicketResource;
use App\Models\KitchenStation;
use App\Models\KitchenTicket;
use App\Models\Printer;
use App\Models\RawMaterial;
use App\Support\BusinessDate;
use App\Support\Printing\PrintQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kitchen display (PLAN §4.12): one board per station (or all), tickets coloured by
 * waiting time. Start → ready (confirm raw materials) → served; recall; reprint KOT.
 * Screens refresh over Reverb (KitchenUpdated) and on a timer.
 */
class KitchenController extends Controller
{
    private const TICKET_WITH = ['station', 'order.table', 'order.waiter', 'order.customer', 'items.modifiers', 'items.parent'];

    public function index(Request $request): Response
    {
        $stations = KitchenStation::query()->active()->with('printer')->orderBy('name')->get();
        $station = $stations->firstWhere('uuid', $request->query('station'));

        $board = KitchenTicket::query()->onBoard()->whereHas('order', fn ($q) => $q->open());
        $counts = (clone $board)->selectRaw('kitchen_station_id, count(*) as n')->groupBy('kitchen_station_id')->pluck('n', 'kitchen_station_id');

        return Inertia::render('kitchen/Index', [
            'stations' => $stations->map(fn (KitchenStation $s) => [
                'id' => $s->uuid,
                'name' => $s->name,
                'has_screen' => $s->has_screen,
                'printer' => $s->printer && ! $s->printer->isTrashed() && $s->printer->is_active ? $s->printer->uuid : null,
                'count' => (int) ($counts[$s->id] ?? 0),
            ])->all(),
            'unrouted' => (int) ($counts[''] ?? $counts[0] ?? 0),
            'station' => $station?->uuid,
            'tickets' => fn () => KitchenTicketResource::collection(
                $this->forStation(clone $board, $station)->with(self::TICKET_WITH)->orderBy('sent_at')->orderBy('id')->limit(200)->get()
            )->resolve(),
            'done' => Inertia::optional(fn () => KitchenTicketResource::collection(
                $this->forStation(KitchenTicket::query(), $station)
                    ->where('status', KitchenStatus::Served)
                    ->whereDate('business_date', BusinessDate::for())
                    ->whereHas('order', fn ($q) => $q->open())
                    ->with(self::TICKET_WITH)->latest('served_at')->limit(30)->get()
            )->resolve()),
            'confirm' => Inertia::optional(fn () => $this->toConfirm($request)),
            'materials' => Inertia::optional(fn () => RawMaterial::query()->active()->with('stockUnit')->orderBy('name')->get()
                ->map(fn (RawMaterial $m) => ['id' => $m->uuid, 'name' => $m->name, 'unit' => $m->stockUnit?->short_name])->all()),
            'printers' => Printer::deviceOptions(),
            'rules' => [
                'amber_after' => (int) setting('kitchen.amber_after'),
                'red_after' => (int) setting('kitchen.red_after'),
                'confirm_consumption' => (bool) setting('kitchen.confirm_consumption'),
                'kds' => (bool) setting('printing.kds'),
                'print_method' => setting('printing.method'),
            ],
        ]);
    }

    public function start(KitchenTicket $ticket, MoveKitchenTicket $move): RedirectResponse
    {
        $move->start($ticket);

        return back();
    }

    public function ready(KitchenReadyRequest $request, KitchenTicket $ticket, MarkKitchenReady $ready): RedirectResponse
    {
        $ready->handle($ticket, $request->itemIds($ticket), $request->consumption($ticket), $request->user('admin'));

        return back();
    }

    public function serve(KitchenTicket $ticket, MoveKitchenTicket $move): RedirectResponse
    {
        $move->serve($ticket);

        return back();
    }

    public function recall(KitchenTicket $ticket, MoveKitchenTicket $move): RedirectResponse
    {
        $move->recall($ticket);

        return back()->with('success', "{$ticket->code()} recalled.");
    }

    public function reprint(KitchenTicket $ticket): RedirectResponse
    {
        $job = PrintQueue::kot($ticket, reprint: true);

        return $job
            ? back()->with('success', "{$ticket->code()} sent to {$job->printer->name}.")
            : back()->with('error', "{$ticket->code()} has no kitchen printer — set one on the kitchen station.");
    }

    private function forStation(Builder $query, ?KitchenStation $station): Builder
    {
        return $station ? $query->where('kitchen_station_id', $station->id) : $query;
    }

    /** Lines of `?confirm=<ticket>` (optionally `&item=<line>`) waiting for the cook to confirm raw materials. */
    private function toConfirm(Request $request): array
    {
        $ticket = KitchenTicket::query()->where('uuid', (string) $request->query('ticket'))->first();
        if (! $ticket) {
            return [];
        }

        $item = $request->filled('item') ? $ticket->items()->where('uuid', (string) $request->query('item'))->first() : null;

        return MarkKitchenReady::toConfirm($ticket, $item);
    }
}
