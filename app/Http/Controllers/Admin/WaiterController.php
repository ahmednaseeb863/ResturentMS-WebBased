<?php

namespace App\Http\Controllers\Admin;

use App\Actions\RequestBill;
use App\Actions\SaveOrder;
use App\Actions\ServeOrderItems;
use App\Enums\OrderType;
use App\Enums\TableStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\WaiterOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\DiningTable;
use App\Models\Order;
use App\Support\PosMenu;
use App\Support\WaiterFloor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Waiter app (PLAN §4.11) — phones / tablets: the table grid with live status, a table's
 * order (items ready to serve, send more to the kitchen), mark served and ask for the
 * bill. Orders need an open shift in the branch; payment is taken at a cash counter.
 */
class WaiterController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('waiter/Index', [
            'tables' => WaiterFloor::tables($request->user('admin')->employee),
            'shiftOpen' => WaiterFloor::shiftOpen(),
            'me' => $request->user('admin')->employee?->uuid, // alerts: my orders first
        ]);
    }

    /** One table: its open order (or a new one) and the menu. */
    public function table(Request $request, DiningTable $table): Response|RedirectResponse
    {
        if (! $table->is_active) {
            return to_route('waiter.index')->with('error', "“{$table->name}” is switched off.");
        }

        $order = Order::query()->open()->where('table_id', $table->id)->first();
        $table->loadMissing('area');

        return Inertia::render('waiter/Table', [
            'table' => [
                'id' => $table->uuid,
                'name' => $table->name,
                'area' => $table->area?->name,
                'capacity' => $table->capacity,
                'status' => ['value' => $table->status->value, 'label' => $table->status->label()],
            ],
            'order' => $order ? (new OrderResource($order->load(PosController::ORDER_WITH)))->resolve() : null,
            'categories' => PosMenu::categories(),
            'items' => PosMenu::items(),
            'deals' => PosMenu::deals(),
            'rules' => PosMenu::rules(),
            'shiftOpen' => WaiterFloor::shiftOpen(),
            'me' => $request->user('admin')->employee?->uuid,
            'tableStatuses' => collect(TableStatus::cases())->filter->isManual()
                ->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()])->values()->all(),
        ]);
    }

    /** A new dine-in order on the table. */
    public function store(WaiterOrderRequest $request, DiningTable $table, SaveOrder $save): RedirectResponse
    {
        $this->requireShift();
        $order = $save->handle($request);

        return to_route('waiter.table', $table)->with('success', $this->sentMessage($order, $save, true));
    }

    /** More items for the table's order. */
    public function update(WaiterOrderRequest $request, Order $order, SaveOrder $save): RedirectResponse
    {
        $this->requireShift();
        $order = $save->handle($request);

        return to_route('waiter.table', $order->table)->with('success', $this->sentMessage($order, $save, false));
    }

    /** Ready items taken to the table (`items` = line uuids; none = all ready). */
    public function serve(Request $request, Order $order, ServeOrderItems $serve): RedirectResponse
    {
        $data = $request->validate(['items' => ['nullable', 'array', 'max:200'], 'items.*' => ['uuid']]);
        $this->dineIn($order);

        $count = $serve->handle($order, $data['items'] ?? []);

        return back()->with('success', $count === 1 ? '1 item served.' : "{$count} items served.");
    }

    public function requestBill(Request $request, Order $order, RequestBill $bill): RedirectResponse
    {
        $this->dineIn($order);
        $job = $bill->handle($order, $request->user('admin'));

        return back()->with('success', $job
            ? "Bill asked for — printing at {$job->printer->name}."
            : 'Bill asked for — the cashier has been told.');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function requireShift(): void
    {
        if (! WaiterFloor::shiftOpen()) {
            throw ValidationException::withMessages(['order' => 'No cash counter is open — ask the cashier to open a shift first.']);
        }
    }

    private function dineIn(Order $order): void
    {
        if ($order->type !== OrderType::DineIn || ! $order->table_id) {
            throw ValidationException::withMessages(['order' => 'Only dine-in orders are handled in the waiter app.']);
        }
    }

    private function sentMessage(Order $order, SaveOrder $save, bool $placing): string
    {
        $kitchen = collect($save->sent)->contains(fn ($item) => $item->kitchen_status !== null);
        $where = $kitchen ? ' and sent to the kitchen.' : '.';

        return $placing ? "Order {$order->code()} placed{$where}" : "New items added to {$order->code()}{$where}";
    }
}
