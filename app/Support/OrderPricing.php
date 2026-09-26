<?php

namespace App\Support;

use App\Actions\SplitBill;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;

/**
 * The bill of an order — the only place totals are calculated (PLAN §4.13). Prices are
 * tax-exclusive; tax is on the whole bill:
 *
 *   items total    = Σ (unit price + add-ons) × qty
 *   discount       = line discounts + order discount (on the items after line discounts)
 *   net            = items total − discount
 *   service charge = net × rate            (dine-in, unless removed)
 *   delivery fee   = zone / default fee    (delivery)
 *   tax            = (net + service charge + delivery fee) × tax rate
 *   grand total    = net + service charge + delivery fee + tax ± round-off (Payments setting)
 *
 * Rates are copied onto the order when it is placed (`snapshot`), so settings changed
 * later never change its bill. A held order's bill is of its held cart.
 */
class OrderPricing
{
    /** Copy today's service charge / tax / delivery fee settings onto the order. */
    public static function snapshot(Order $order): void
    {
        $branch = $order->branch_id;

        $order->service_charge_rate = $order->type === OrderType::DineIn && setting('service_charge.enabled', $branch)
            ? (float) setting('service_charge.rate', $branch) : 0;
        $order->tax_name = setting('tax.name', $branch);
        $order->tax_rate = setting('tax.enabled', $branch) ? (float) setting('tax.rate', $branch) : 0;
        $order->delivery_fee = $order->type === OrderType::Delivery ? static::deliveryFee($order) : 0;
    }

    /** The delivery zone's fee (Delivery setting "use zones"), else the default fee. */
    public static function deliveryFee(Order $order): float
    {
        $zone = setting('delivery.use_zones', $order->branch_id) && $order->relationLoaded('delivery')
            ? $order->delivery?->zone
            : null;

        return $zone ? (float) $zone->fee : (float) setting('delivery.default_fee', $order->branch_id);
    }

    /** Recalculate and save the order's totals (and its order discount's amount). */
    public static function apply(Order $order): Order
    {
        $lines = $order->isDraft()
            ? collect($order->held_items ?? [])->map(fn (array $l) => ['gross' => (float) $l['gross'], 'discount' => (float) $l['discount_amount']])
            : $order->lines()->live()->get()->map(fn (OrderItem $i) => ['gross' => $i->gross(), 'discount' => (float) $i->discount_amount]);

        $discount = $order->orderDiscount()->first();
        $totals = static::totals($order, $lines->all(), $discount);

        if ($discount && (float) $discount->amount !== $totals['order_discount']) {
            $discount->update(['amount' => $totals['order_discount']]);
        }

        $order->fill(collect($totals)->except(['order_discount', 'line_discounts'])->all());
        if (! $order->isDraft()) {
            $order->syncPaymentStatus(); // new items on a paid order → part paid again
        }
        $order->save();

        if ($order->split_mode) {
            SplitBill::afterRepricing($order);
        }

        return $order;
    }

    /**
     * @param  list<array{gross: float, discount: float}>  $lines
     * @return array<string, float>
     */
    public static function totals(Order $order, array $lines, ?OrderDiscount $discount = null): array
    {
        $items = round(array_sum(array_column($lines, 'gross')), 2);
        $lineDiscounts = round(array_sum(array_column($lines, 'discount')), 2);
        $orderDiscount = $discount ? $discount->amountOn(round($items - $lineDiscounts, 2)) : 0.0;

        $discountTotal = round(min($items, $lineDiscounts + $orderDiscount), 2);
        $net = round($items - $discountTotal, 2);

        $service = $order->type === OrderType::DineIn && ! $order->service_charge_removed
            ? round($net * (float) $order->service_charge_rate / 100, 2) : 0.0;
        $delivery = $order->type === OrderType::Delivery ? round((float) $order->delivery_fee, 2) : 0.0;

        $taxBase = round($net + $service + $delivery, 2);
        $tax = round($taxBase * (float) $order->tax_rate / 100, 2);
        $before = round($taxBase + $tax, 2);
        $grand = static::round($before, (string) setting('payments.rounding', $order->branch_id));

        return [
            'items_total' => $items,
            'line_discounts' => $lineDiscounts,
            'order_discount' => $orderDiscount,
            'discount_total' => $discountTotal,
            'net_total' => $net,
            'service_charge' => $service,
            'delivery_fee' => $delivery,
            'tax_total' => $tax,
            'round_off' => round($grand - $before, 2),
            'grand_total' => $grand,
        ];
    }

    /** Payments setting "round the bill to": none / nearest 1 / 5 / 10. */
    public static function round(float $amount, string $rule): float
    {
        $step = (int) $rule;

        return $step > 0 ? round(round($amount / $step) * $step, 2) : round($amount, 2);
    }
}
