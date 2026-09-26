<?php

namespace App\Actions;

use App\Enums\DiscountType;
use App\Models\Admin;
use App\Models\Discount;
use App\Models\Order;

/**
 * Replaces the discount on the whole order: the old one is trashed, the new one copies
 * the predefined discount's type, value, cap and minimum (or the typed-in values).
 * The amount is worked out by OrderPricing. Run inside the caller's transaction.
 */
class SetOrderDiscount
{
    /**
     * @param  array{preset: ?Discount, type: DiscountType, value: float, reason: ?string}|null  $wanted
     * @return string what changed, for the activity log
     */
    public function handle(Order $order, ?array $wanted, Admin $admin, ?Admin $approver = null): string
    {
        $order->orderDiscount?->trash($wanted ? 'Replaced' : 'Removed');

        if (! $wanted) {
            $order->setRelation('orderDiscount', null);

            return 'removed';
        }

        $discount = $order->discounts()->create([
            'discount_id' => $wanted['preset']?->id,
            'name' => $wanted['preset']?->name ?? 'Manual discount',
            'type' => $wanted['type'],
            'value' => $wanted['value'],
            'max_amount' => $wanted['preset']?->max_amount,
            'min_amount' => $wanted['preset']?->min_order_amount,
            'amount' => 0, // OrderPricing works it out
            'reason' => $wanted['reason'],
            'approved_by' => $approver?->id,
            'created_by' => $admin->id,
        ]);
        $order->setRelation('orderDiscount', $discount);

        return "{$discount->name} ({$discount->valueText()})";
    }
}
