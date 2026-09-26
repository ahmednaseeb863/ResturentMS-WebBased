<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CancelOrder;
use App\Actions\SetOrderDiscount;
use App\Actions\VoidOrderItem;
use App\Enums\DiscountScope;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\PosOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Discount;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Support\Activity;
use App\Support\BusinessDate;
use App\Support\CartError;
use App\Support\OrderCart;
use App\Support\OrderPricing;
use App\Support\PosMenu;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Orders of the current branch: list, detail, void, cancel, discount, service charge (PLAN §5). */
class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $status = (string) $request->query('status');
        $type = OrderType::tryFrom((string) $request->query('type'));
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));
        $search = trim((string) $request->query('search'));

        $filtered = fn (Builder $q) => $q
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($from, fn ($q) => $q->whereDate('business_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('business_date', '<=', $to))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('order_number', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $search).'%'))
                ->orWhereHas('table', fn ($t) => $t->where('name', 'like', "%{$search}%"))));

        $orders = Order::query()->tap($filtered)
            ->when($status === 'open', fn ($q) => $q->open()->where('status', '!=', OrderStatus::Draft))
            ->when($status && $status !== 'open', fn ($q) => $q->where('status', $status))
            ->with(['table', 'customer', 'waiter', 'createdBy'])
            ->withSum(['lines as live_quantity' => fn ($q) => $q->whereNull('voided_at')], 'quantity')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // stats of the filtered range: placed orders (not held / cancelled)
        $sales = Order::query()->tap($filtered)->whereNotIn('status', [OrderStatus::Draft, OrderStatus::Cancelled])->toBase()
            ->selectRaw('count(*) as orders, coalesce(sum(grand_total), 0) as total')->first();

        return Inertia::render('orders/Index', [
            'orders' => OrderResource::collection($orders),
            'filters' => ['status' => $status, 'type' => $type?->value ?? '', 'from' => $from ?? '', 'to' => $to ?? '', 'search' => $search],
            'stats' => [
                'orders' => (int) $sales->orders,
                'total' => (float) $sales->total,
                'open' => Order::query()->tap($filtered)->open()->where('status', '!=', OrderStatus::Draft)->count(),
                'held' => Order::query()->tap($filtered)->where('status', OrderStatus::Draft)->count(),
                'cancelled' => Order::query()->tap($filtered)->where('status', OrderStatus::Cancelled)->count(),
            ],
            'statuses' => [['value' => 'open', 'label' => 'Open (not paid)'], ...OrderStatus::options()],
            'types' => OrderType::options(),
            'businessDate' => BusinessDate::for(),
        ]);
    }

    public function show(Order $order): Response
    {
        $order->load(PosController::ORDER_WITH);

        return Inertia::render('orders/Show', [
            'order' => (new OrderResource($order))->resolve(),
            'history' => $order->histories()->with('admin')->get()->map(fn ($h) => [
                'from' => $h->from_status?->label(),
                'to' => $h->to_status->label(),
                'by' => $h->admin?->name,
                'note' => $h->note,
                'at' => OrderResource::iso($h->created_at),
            ])->all(),
            'tickets' => $order->tickets()->with('station')->withCount('items')->get()->map(fn ($t) => [
                'id' => $t->uuid,
                'code' => $t->code(),
                'station' => $t->station?->name ?? 'Kitchen',
                'status' => $t->status->label(),
                'items' => $t->items_count,
                'sent_at' => OrderResource::iso($t->sent_at),
            ])->all(),
            'discounts' => $order->isOpen() ? PosMenu::discounts() : [],
            'rules' => PosMenu::rules(),
        ]);
    }

    public function cancel(Request $request, Order $order, CancelOrder $cancel): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'wasted' => ['boolean'],
            'pin' => ['nullable', 'digits_between:4,6'],
        ], ['reason.required' => 'Say why the order is cancelled.']);

        $approver = ! $order->isDraft() && setting('approvals.pin_void')
            ? PosOrderRequest::approve(['orders.cancel'], $data['pin'] ?? null)
            : null;

        $cancel->handle($order, $data['reason'], (bool) ($data['wasted'] ?? false), $request->user('admin'), $approver);

        return back()->with('success', "Order {$order->code()} cancelled.");
    }

    public function void(Request $request, Order $order, OrderItem $item, VoidOrderItem $void): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:'.$item->quantity],
            'reason' => ['required', 'string', 'max:255'],
            'wasted' => ['boolean'],
            'pin' => ['nullable', 'digits_between:4,6'],
        ], ['reason.required' => 'Say why the item is voided.', 'quantity.max' => "Only {$item->quantity} on the order."]);

        VoidOrderItem::checkVoidable($order, $item, (int) $data['quantity']);
        $approver = setting('approvals.pin_void') ? PosOrderRequest::approve(['orders.items.void'], $data['pin'] ?? null) : null;

        $void->handle($item, (int) $data['quantity'], $data['reason'], (bool) ($data['wasted'] ?? false), $request->user('admin'), $approver);

        return back()->with('success', "{$data['quantity']} × {$item->fullName()} voided.");
    }

    /** Set, change or remove the discount on the whole order. */
    public function discount(Request $request, Order $order, SetOrderDiscount $set): RedirectResponse
    {
        $data = $request->validate([
            'discount' => ['nullable', 'array'],
            'discount.discount' => ['nullable', 'uuid'],
            'discount.type' => ['nullable', 'in:percent,fixed'],
            'discount.value' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'discount.reason' => ['nullable', 'string', 'max:255'],
            'pin' => ['nullable', 'digits_between:4,6'],
        ]);
        $this->checkChangeable($order);

        $wanted = null;
        if (! empty($data['discount']['discount']) || ! empty($data['discount']['value'])) {
            try {
                $presets = Discount::query()->where('uuid', $data['discount']['discount'] ?? '')->get()->keyBy('uuid');
                $wanted = OrderCart::discount($data['discount'], $presets, BusinessDate::for(), DiscountScope::Order);
            } catch (CartError $e) {
                throw ValidationException::withMessages(['discount' => $e->getMessage()]);
            }
        }

        $approver = null;
        if ($wanted) {
            $base = round((float) $order->items_total - ((float) $order->discount_total - (float) ($order->orderDiscount?->amount ?? 0)), 2);
            $amount = OrderDiscount::calculate($wanted['type'], $wanted['value'], $base, $wanted['preset']?->max_amount, $wanted['preset']?->min_order_amount);
            if (PosOrderRequest::discountNeedsApproval($wanted['preset'], $amount, $base)) {
                $approver = PosOrderRequest::approve(['orders.discount'], $data['pin'] ?? null);
            }
        }

        DB::transaction(function () use ($order, $wanted, $set, $request, $approver) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $change = $set->handle($order, $wanted, $request->user('admin'), $approver);
            OrderPricing::apply($order);

            Activity::log('order_discount', $order, array_filter(['discount' => $change, 'total' => money($order->grand_total), 'approved_by' => $approver?->name]));
        });

        return back()->with('success', $wanted ? 'Discount applied.' : 'Discount removed.');
    }

    /** Remove / put back the service charge of a dine-in order. */
    public function serviceCharge(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['remove' => ['required', 'boolean'], 'pin' => ['nullable', 'digits_between:4,6']]);
        $this->checkChangeable($order);
        $remove = (bool) $data['remove'];

        if ($order->type !== OrderType::DineIn) {
            return back()->with('error', 'Only dine-in orders have a service charge.');
        }
        if ($remove && ! setting('service_charge.removable')) {
            return back()->with('error', 'Service charge can’t be removed in this branch.');
        }

        $approver = $remove && setting('approvals.pin_remove_service_charge')
            ? PosOrderRequest::approve(['orders.service-charge'], $data['pin'] ?? null)
            : null;

        DB::transaction(function () use ($order, $remove, $approver) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $order->service_charge_removed = $remove;
            OrderPricing::apply($order);

            Activity::log('order_service_charge', $order, array_filter(['service_charge' => $remove ? 'removed' : 'added back', 'approved_by' => $approver?->name]));
        });

        return back()->with('success', $remove ? 'Service charge removed.' : 'Service charge added back.');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function checkChangeable(Order $order): void
    {
        if (! $order->isOpen() || (float) $order->paid_total > 0) {
            throw ValidationException::withMessages(['order' => "Order {$order->code()} can't be changed any more."]);
        }
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
