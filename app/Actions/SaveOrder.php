<?php

namespace App\Actions;

use App\Enums\ConsumptionStatus;
use App\Enums\DeliveryStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\StockMovementType;
use App\Enums\TableStatus;
use App\Http\Requests\PosOrderRequest;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Deal;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\MenuItem;
use App\Models\MenuItemVariant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReadyItem;
use App\Support\Activity;
use App\Support\BusinessDate;
use App\Support\CartLine;
use App\Support\CurrentBranch;
use App\Support\KitchenSync;
use App\Support\OrderCart;
use App\Support\OrderPricing;
use App\Support\Printing\PrintQueue;
use App\Support\StockLedger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a POS order (PLAN §4.10 / §5) in one transaction:
 *   hold — park a new order (draft) with its cart
 *   send — place the order / add items: order lines, kitchen tickets per station,
 *          ready items taken out of stock, order number on first send
 *   save — change the details (table, waiter, customer, discount…) of a saved order
 * Totals are recalculated at the end (OrderPricing).
 */
class SaveOrder
{
    /** @var list<OrderItem> lines created by the last `send` */
    public array $sent = [];

    public function __construct(private StockLedger $stock, private SetOrderDiscount $discount) {}

    public function handle(PosOrderRequest $request, ?Admin $approver = null): Order
    {
        $admin = $request->user('admin');
        $action = $request->action();
        $lines = $request->lines();

        return DB::transaction(function () use ($request, $admin, $approver, $action, $lines) {
            // one writer per branch at a time: order / ticket numbers and tables stay consistent
            Branch::query()->lockForUpdate()->find(app(CurrentBranch::class)->id());

            $order = $request->order()
                ? Order::query()->lockForUpdate()->findOrFail($request->order()->id)
                : new Order([
                    'source' => OrderSource::Pos,
                    'status' => OrderStatus::Draft,
                    'business_date' => BusinessDate::for(),
                    'created_by' => $admin->id,
                ]);

            $this->checkAction($order, $action, $lines);

            $wasNew = ! $order->exists;
            $wasDraft = $order->isDraft();
            $oldTable = $order->table_id;
            $this->fillDetails($order, $request);
            $this->checkTable($order, $oldTable);
            $order->save();

            if ($wasNew && $action === 'hold') {
                $order->histories()->create(['to_status' => OrderStatus::Draft->value, 'admin_id' => $admin->id]);
            }

            $this->syncTables($order, $oldTable);
            $this->syncDelivery($order, $request);
            $discountChange = $request->discountChanged()
                ? $this->discount->handle($order, $request->orderDiscount(), $admin, $approver)
                : null;

            $sent = [];
            if ($action === 'hold') {
                $order->held_items = array_map(fn (CartLine $l) => $l->payload(), $lines);
                $order->save();
            } elseif ($action === 'send') {
                $sent = $this->sent = $this->send($order, $request, $lines, $admin, $approver);
            }

            OrderPricing::apply($order);

            if ($action === 'send') {
                $this->checkDeliveryMinimum($order);
            }

            $event = match ($action) {
                'hold' => 'order_held',
                'send' => $wasDraft ? 'order_placed' : 'order_items_sent',
                default => 'order_updated',
            };
            $this->log($order, $event, $sent, $request, $discountChange, $approver);

            return $order;
        });
    }

    // ── steps ────────────────────────────────────────────────────────────

    private function checkAction(Order $order, string $action, array $lines): void
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['order' => $message]);

        if ($order->exists && ! $order->isOpen()) {
            $fail("Order {$order->code()} is {$order->status->label()} — it can't be changed.");
        }
        match ($action) {
            'hold' => match (true) {
                ! setting('orders.hold_orders') => $fail('Holding orders is switched off for this branch.'),
                $order->exists && ! $order->isDraft() => $fail("Order {$order->code()} is already sent — send the new items or remove them."),
                ! $lines => $fail('Add items before holding the order.'),
                default => null,
            },
            'send' => $lines ?: $fail('Add items to send.'),
            'save' => match (true) {
                ! $order->exists || $order->isDraft() => $fail('Hold or send the order to save it.'),
                (bool) $lines => $fail('Send the new items first.'),
                default => null,
            },
        };
    }

    private function fillDetails(Order $order, PosOrderRequest $request): void
    {
        $type = $request->orderType();
        $dineIn = $type === OrderType::DineIn;

        $order->type = $type;
        $order->table_id = $dineIn ? $request->table()?->id : null;
        $order->waiter_id = $dineIn ? $request->waiter()?->id : null;
        $order->guests = $dineIn ? $request->validated('guests') : null;
        $order->user_id = $request->customer()?->id;
        $order->notes = $request->validated('notes');
        $order->service_charge_removed = $request->removeServiceCharge();

        // a held order follows today's rates until it is placed
        if ($order->isDraft()) {
            OrderPricing::snapshot($order);
        }
    }

    /** One open order per table (MySQL enforces it too). */
    private function checkTable(Order $order, ?int $oldTable): void
    {
        if (! $order->table_id || $order->table_id === $oldTable) {
            return;
        }

        $busy = Order::query()->open()->where('table_id', $order->table_id)
            ->when($order->exists, fn ($q) => $q->whereKeyNot($order->id))->first();

        if ($busy) {
            throw ValidationException::withMessages(['table' => "{$busy->table->name} already has {$busy->code()} open — add the items to that order."]);
        }
    }

    /** The table of an open order is occupied; a table left behind becomes available. */
    private function syncTables(Order $order, ?int $oldTable): void
    {
        if ($order->table_id === $oldTable) {
            return;
        }

        if ($order->table_id) {
            DiningTable::query()->whereKey($order->table_id)->first()?->forceFill(['status' => TableStatus::Occupied])->saveQuietly();
        }

        if ($oldTable) {
            static::releaseTable($oldTable);
        }
    }

    /** Free a table when no other open order sits on it. */
    public static function releaseTable(int $tableId): void
    {
        if (! Order::query()->open()->where('table_id', $tableId)->exists()) {
            DiningTable::query()->whereKey($tableId)->first()?->forceFill(['status' => TableStatus::Available])->saveQuietly();
        }
    }

    private function syncDelivery(Order $order, PosOrderRequest $request): void
    {
        if ($order->type !== OrderType::Delivery) {
            // a held order switched away from delivery
            $order->delivery?->update(['status' => DeliveryStatus::Cancelled]);

            return;
        }

        $saved = $request->address();
        $text = $saved
            ? collect([$saved->address, $saved->area, $saved->landmark ? "Near {$saved->landmark}" : null])->filter()->join(', ')
            : trim((string) $request->validated('address_text'));

        $delivery = $order->delivery()->updateOrCreate([], [
            'branch_id' => $order->branch_id,
            'user_address_id' => $saved?->id,
            'address' => $text,
            'phone' => $order->customer?->phone,
            'fee' => $order->delivery_fee,
            'status' => $order->delivery && $order->delivery->status !== DeliveryStatus::Cancelled
                ? $order->delivery->status : DeliveryStatus::Pending,
        ]);
        $order->setRelation('delivery', $delivery);
    }

    /**
     * Turn cart lines into order lines + kitchen tickets, take ready items out of stock,
     * and place the order (number, status) on its first send.
     *
     * @param  list<CartLine>  $lines
     * @return list<OrderItem>
     */
    private function send(Order $order, PosOrderRequest $request, array $lines, Admin $admin, ?Admin $approver): array
    {
        if ($order->type === OrderType::DineIn && ! $order->table_id) {
            throw ValidationException::withMessages(['table' => 'Pick the table of this dine-in order.']);
        }
        if ($order->type === OrderType::Delivery) {
            if (! $order->user_id && setting('orders.require_customer_for_delivery')) {
                throw ValidationException::withMessages(['customer' => 'Pick the customer of this delivery.']);
            }
            if (blank($order->delivery?->address)) {
                throw ValidationException::withMessages(['address' => 'Enter the delivery address.']);
            }
        }

        OrderCart::checkStock($lines);

        $firstSend = $order->isDraft();
        if ($firstSend) {
            $this->number($order);
        }

        $now = now();
        $base = ['order_id' => $order->id, 'sent_by' => $admin->id, 'sent_at' => $now];
        $items = [];
        foreach ($lines as $line) {
            array_push($items, ...$this->createLine($line, $base, $admin, $approver));
        }

        $this->createTickets($order, collect($items), $now);
        $this->takeStock($order, $items);

        $order->held_items = null;
        $order->save();

        $hasKitchen = $order->items()->live()->whereNotNull('kitchen_status')->exists();
        $newKitchen = collect($items)->contains(fn (OrderItem $i) => $i->kitchen_status !== null);

        if ($firstSend) {
            $order->placed_at = $now;
            $order->moveTo($hasKitchen ? OrderStatus::Placed : OrderStatus::Ready);
        } elseif ($newKitchen && in_array($order->status, [OrderStatus::Ready, OrderStatus::Served], true)) {
            $order->moveTo(OrderStatus::Placed, 'More items sent to the kitchen');
        }

        return $items;
    }

    /** "#012" per branch per business day (or never reset), with the settings' prefix. */
    private function number(Order $order): void
    {
        $date = BusinessDate::for($order->branch_id);
        $last = Order::query()
            ->when(setting('orders.number_reset') === 'daily', fn ($q) => $q->whereDate('business_date', $date))
            ->max('number');

        $order->number = (int) $last + 1;
        $order->business_date = $date;
        $order->order_number = setting('orders.number_prefix').str_pad((string) $order->number, (int) setting('orders.number_padding'), '0', STR_PAD_LEFT);
        OrderPricing::snapshot($order);
    }

    /** @return list<OrderItem> the line and, for a deal, its picks */
    private function createLine(CartLine $line, array $base, Admin $admin, ?Admin $approver): array
    {
        $item = $line->item;
        $parent = OrderItem::create([
            ...$base,
            ...$this->routing($item, $line->variant, $line->modifiers),
            'sellable_type' => $item->getMorphClass(),
            'sellable_id' => $item->id,
            'deal_id' => $item instanceof Deal ? $item->id : null,
            'variant_id' => $line->variant?->id,
            'item_name' => $item->name,
            'variant_name' => $line->variant?->name,
            'quantity' => $line->quantity,
            'unit_price' => $line->unitPrice(),
            'modifiers_total' => $line->modifiersTotal(),
            'discount_amount' => $line->discountAmount,
            'line_total' => round($line->gross() - $line->discountAmount, 2),
            'notes' => $line->notes,
        ]);

        foreach ($line->modifiers as $modifier) {
            $parent->modifiers()->create(['modifier_id' => $modifier->id, 'name' => $modifier->name, 'price' => $modifier->price]);
        }

        if ($line->discount) {
            $parent->order->discounts()->create([
                'order_item_id' => $parent->id,
                'discount_id' => $line->discount['preset']?->id,
                'name' => $line->discount['preset']?->name ?? 'Manual discount',
                'type' => $line->discount['type'],
                'value' => $line->discount['value'],
                'max_amount' => $line->discount['preset']?->max_amount,
                'min_amount' => $line->discount['preset']?->min_order_amount,
                'amount' => $line->discountAmount,
                'reason' => $line->discount['reason'],
                'approved_by' => $approver?->id,
                'created_by' => $admin->id,
            ]);
        }

        $created = [$parent];
        foreach ($line->picks as ['slot' => $slot, 'option' => $option]) {
            $sellable = $option->sellable;
            $variant = $option->variant;
            $created[] = OrderItem::create([
                ...$base,
                ...$this->routing($sellable, $variant, collect()),
                'parent_order_item_id' => $parent->id,
                'deal_id' => $item->id,
                'sellable_type' => $sellable->getMorphClass(),
                'sellable_id' => $sellable->id,
                'variant_id' => $variant?->id,
                'item_name' => $sellable->name,
                'variant_name' => $variant?->name,
                'quantity' => $slot->quantity * $line->quantity,
                'unit_price' => 0,
                'line_total' => 0,
                'notes' => $line->notes,
            ]);
        }

        return $created;
    }

    /**
     * Kitchen station and consumption of a line: menu items always go to the kitchen
     * (own station, else the category's); ready items only when they have a station.
     */
    private function routing(MenuItem|ReadyItem|Deal $item, ?MenuItemVariant $variant, Collection $modifiers): array
    {
        if ($item instanceof Deal) {
            return ['kitchen_status' => null, 'kitchen_station_id' => null, 'consumption_status' => ConsumptionStatus::NotRequired];
        }

        if ($item instanceof ReadyItem) {
            return [
                'kitchen_station_id' => $item->kitchen_station_id,
                'kitchen_status' => $item->kitchen_station_id ? KitchenStatus::Pending : null,
                'consumption_status' => ConsumptionStatus::NotRequired,
            ];
        }

        $hasRecipe = ($variant && $variant->recipeItems()->exists())
            || $item->recipeItems()->exists()
            || $modifiers->contains(fn ($m) => $m->recipeItems()->exists());

        return [
            'kitchen_station_id' => $item->kitchen_station_id ?? $item->loadMissing('category')->category?->kitchen_station_id,
            'kitchen_status' => KitchenStatus::Pending,
            'consumption_status' => $hasRecipe ? ConsumptionStatus::Pending : ConsumptionStatus::NotRequired,
        ];
    }

    /** One ticket per station for this send, numbered per business day; printed / shown on the KDS. */
    private function createTickets(Order $order, Collection $items, $now): void
    {
        $kitchen = $items->filter(fn (OrderItem $i) => $i->kitchen_status !== null);
        $date = BusinessDate::for($order->branch_id);
        $number = (int) KitchenTicket::query()->whereDate('business_date', $date)->max('number');

        $tickets = [];
        foreach ($kitchen->groupBy(fn (OrderItem $i) => $i->kitchen_station_id ?? 0) as $stationId => $stationItems) {
            $ticket = KitchenTicket::create([
                'order_id' => $order->id,
                'kitchen_station_id' => $stationId ?: null,
                'business_date' => $date,
                'number' => ++$number,
                'status' => KitchenStatus::Pending,
                'sent_at' => $now,
            ]);
            OrderItem::query()->whereKey($stationItems->pluck('id')->all())->update(['kitchen_ticket_id' => $ticket->id]);
            $tickets[] = $ticket->setRelation('order', $order);
        }

        if ($tickets) {
            PrintQueue::sent($tickets);
            KitchenSync::changed($order->branch_id);
        }
    }

    /** Ready items leave stock when the order is placed (PLAN §4.10). */
    private function takeStock(Order $order, array $items): void
    {
        $allowNegative = setting('inventory.out_of_stock') === 'warn' || setting('inventory.allow_negative_stock');

        foreach ($items as $item) {
            if ($item->sellable_type !== 'ready_item') {
                continue;
            }
            $this->stock->record(
                $item->sellable, StockMovementType::Sale, -$item->quantity,
                note: "Order {$order->code()}", reference: $item, allowNegative: $allowNegative,
            );
        }
    }

    private function checkDeliveryMinimum(Order $order): void
    {
        $minimum = (float) setting('delivery.min_order_amount');

        if ($order->type === OrderType::Delivery && $minimum > 0 && (float) $order->net_total < $minimum) {
            throw ValidationException::withMessages(['order' => 'Delivery orders must be at least '.money($minimum).' — this one is '.money($order->net_total).'.']);
        }
    }

    private function log(Order $order, string $event, array $sent, PosOrderRequest $request, ?string $discount, ?Admin $approver): void
    {
        $lines = collect($sent)->filter(fn (OrderItem $i) => ! $i->parent_order_item_id)
            ->map(fn (OrderItem $i) => "{$i->quantity} × {$i->fullName()}")->all();

        $properties = array_filter([
            'items' => $lines ?: null,
            'total' => money($order->grand_total),
            'discount' => $discount,
            'service_charge' => $request->serviceChargeRemovedNow() ? 'removed' : null,
            'approved_by' => $approver?->name,
        ]);

        Activity::log($event, $order, $properties);
    }
}
