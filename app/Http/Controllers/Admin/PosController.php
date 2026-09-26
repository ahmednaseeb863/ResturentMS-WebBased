<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CancelOrder;
use App\Actions\SaveCustomer;
use App\Actions\SaveOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Requests\PosOrderRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Support\PosMenu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cashier POS (PLAN §4.10): pick items, hold / recall, send to the kitchen. Taking
 * orders needs the cashier's shift to be open on their counter.
 */
class PosController extends Controller
{
    /** What the POS / order detail load with an order. */
    public const ORDER_WITH = [
        'table', 'waiter', 'customer', 'createdBy', 'cancelledBy', 'orderDiscount.discount', 'delivery.savedAddress',
        'lines.modifiers', 'lines.children', 'lines.discount', 'lines.station', 'lines.ticket', 'lines.voidedBy',
    ];

    public function index(Request $request): Response|RedirectResponse
    {
        $order = $request->filled('order') ? Order::query()->where('uuid', $request->query('order'))->first() : null;

        if ($order && ! $order->isOpen()) {
            return to_route('orders.show', $order)->with('error', "Order {$order->code()} is {$order->status->label()} — it can't be changed.");
        }

        return Inertia::render('pos/Index', [
            'order' => $order ? (new OrderResource($order->load(self::ORDER_WITH)))->resolve() : null,
            'categories' => PosMenu::categories(),
            'items' => PosMenu::items(),
            'deals' => PosMenu::deals(),
            'tables' => PosMenu::tables(),
            'waiters' => PosMenu::waiters(),
            'discounts' => PosMenu::discounts(),
            'rules' => PosMenu::rules(),
            'openOrders' => fn () => OrderResource::collection(
                Order::query()->open()->with(['table', 'customer'])
                    ->withSum(['lines as live_quantity' => fn ($q) => $q->whereNull('voided_at')], 'quantity')
                    ->latest('id')->limit(100)->get()
            )->resolve(),
            'customers' => Inertia::optional(fn () => $this->searchCustomers((string) $request->query('customer_q'))),
        ]);
    }

    public function store(PosOrderRequest $request, SaveOrder $save): RedirectResponse
    {
        $this->requireShift($request);
        $order = $save->handle($request, $request->approver());

        return $this->saved($order, $request->action(), true, $save->sent);
    }

    public function update(PosOrderRequest $request, Order $order, SaveOrder $save): RedirectResponse
    {
        $this->requireShift($request);
        $placing = $order->isDraft();
        $order = $save->handle($request, $request->approver());

        return $this->saved($order, $request->action(), $placing, $save->sent);
    }

    /** Throw away a held order (it is kept as cancelled). */
    public function discard(Request $request, Order $order, CancelOrder $cancel): RedirectResponse
    {
        if (! $order->isDraft()) {
            return back()->with('error', "Order {$order->code()} is already sent — cancel it from the order instead.");
        }

        $cancel->handle($order, 'Held order discarded', false, $request->user('admin'));

        return to_route('pos.index')->with('success', 'Held order discarded.');
    }

    /** Quick-add a customer from the POS; the new customer comes back in `flash.customer`. */
    public function storeCustomer(CustomerRequest $request, SaveCustomer $save): RedirectResponse
    {
        $customer = $save->handle($request);

        return back()
            ->with('success', "Customer {$customer->name} added.")
            ->with('customer', (new CustomerResource($customer->load('addresses')))->resolve());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function requireShift(Request $request): void
    {
        if (! Shift::openFor($request->user('admin'))) {
            throw ValidationException::withMessages(['order' => 'Open your shift on a cash counter before taking orders.']);
        }
    }

    private function saved(Order $order, string $action, bool $placing, array $sent): RedirectResponse
    {
        $kitchen = collect($sent)->contains(fn ($item) => $item->kitchen_status !== null);

        $message = match ($action) {
            'hold' => 'Order held — recall it from Open Orders.',
            'save' => "Order {$order->code()} saved.",
            default => $placing
                ? "Order {$order->code()} placed".($kitchen ? ' and sent to the kitchen.' : '.')
                : "New items added to {$order->code()}".($kitchen ? ' and sent to the kitchen.' : '.'),
        };

        return to_route('pos.index')->with('success', $message);
    }

    /** Customers by phone (digits anywhere) or name, with their addresses. */
    private function searchCustomers(string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $digits = preg_replace('/\D/', '', $query);

        $customers = Customer::query()
            ->where(fn ($q) => $q->where('name', 'like', "%{$query}%")
                ->when(strlen($digits) >= 3, fn ($q) => $q->orWhere('phone', 'like', "%{$digits}%")))
            ->with('addresses')
            ->orderBy('name')
            ->limit(8)
            ->get();

        return CustomerResource::collection($customers)->resolve();
    }
}
