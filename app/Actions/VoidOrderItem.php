<?php

namespace App\Actions;

use App\Enums\StockMovementType;
use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Activity;
use App\Support\OrderPricing;
use App\Support\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voids a sent line, or part of it (PLAN §4.10): the voided quantity is split off into
 * its own voided row, so what was sent and what was voided both stay on the order.
 * Ready items go back to stock unless they were wasted (opened / served). A deal's
 * picks are voided with it. The bill is recalculated.
 */
class VoidOrderItem
{
    public function __construct(private StockLedger $stock) {}

    public function handle(OrderItem $item, int $quantity, string $reason, bool $wasted, Admin $admin, ?Admin $approver = null): OrderItem
    {
        return DB::transaction(function () use ($item, $quantity, $reason, $wasted, $admin, $approver) {
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $item = OrderItem::query()->lockForUpdate()->findOrFail($item->id);

            static::checkVoidable($order, $item, $quantity);

            $voided = $quantity < $item->quantity ? $this->split($item, $quantity) : $item;
            $this->markVoided($order, $voided, $reason, $wasted, $admin, $approver);

            if ($voided === $item) {
                $item->discount?->trash('Item voided');
            } else {
                $item->discount?->update(['amount' => $item->discount_amount]);
            }

            OrderPricing::apply($order);

            Activity::log('order_item_voided', $order, array_filter([
                'item' => "{$quantity} × {$item->fullName()}",
                'reason' => $reason,
                'wasted' => $wasted ? 'yes' : null,
                'approved_by' => $approver?->name,
            ]));

            return $voided;
        });
    }

    public static function checkVoidable(Order $order, OrderItem $item, int $quantity): void
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['quantity' => $message]);

        match (true) {
            ! $order->isOpen() => $fail("Order {$order->code()} is {$order->status->label()}."),
            (float) $order->paid_total > 0 => $fail('The order has payments — refund instead of voiding.'),
            $item->isVoided() => $fail("“{$item->fullName()}” is already voided."),
            $item->parent_order_item_id !== null => $fail('Void the whole deal, not one of its picks.'),
            $quantity < 1 || $quantity > $item->quantity => $fail("Void 1 to {$item->quantity}."),
            default => null,
        };
    }

    /** Move `$quantity` of the line (and its deal picks) into a new row; returns the new row. */
    private function split(OrderItem $item, int $quantity): OrderItem
    {
        $ratio = $quantity / $item->quantity;
        $discount = round((float) $item->discount_amount * $ratio, 2);

        $copy = $this->copy($item, $quantity, $discount);

        foreach ($item->modifiers as $modifier) {
            $copy->modifiers()->create($modifier->only(['modifier_id', 'name', 'price']));
        }

        foreach ($item->children as $child) {
            $moved = intdiv($child->quantity, $item->quantity) * $quantity;
            $this->copy($child, $moved, 0, ['parent_order_item_id' => $copy->id]);
            $child->update(['quantity' => $child->quantity - $moved]);
        }

        $item->quantity -= $quantity;
        $item->discount_amount = round((float) $item->discount_amount - $discount, 2);
        $item->line_total = round($item->gross() - (float) $item->discount_amount, 2);
        $item->save();

        return $copy;
    }

    private function copy(OrderItem $item, int $quantity, float $discount, array $extra = []): OrderItem
    {
        $copy = $item->replicate(['uuid']);
        $copy->fill([...$extra, 'quantity' => $quantity, 'discount_amount' => $discount, 'split_from_id' => $item->id]);
        $copy->line_total = round($copy->gross() - $discount, 2);
        $copy->save();

        return $copy;
    }

    /** Void the row and its picks; return ready items to stock unless wasted. */
    public function markVoided(Order $order, OrderItem $item, string $reason, bool $wasted, Admin $admin, ?Admin $approver): void
    {
        foreach ([$item, ...$item->children()->live()->get()] as $row) {
            $row->update([
                'voided_at' => now(),
                'voided_by' => $admin->id,
                'void_reason' => $reason,
                'void_wasted' => $wasted,
                'void_approved_by' => $approver?->id,
            ]);

            if ($row->sellable_type === 'ready_item' && ! $wasted) {
                $this->stock->record($row->sellable, StockMovementType::SaleReturn, $row->quantity, note: "Voided on order {$order->code()}", reference: $row);
            }
        }
    }
}
