<?php

namespace App\Actions;

use App\Enums\ConsumptionStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Raw materials nobody confirmed (kitchens working from printed tickets) are deducted at
 * the recipe quantities (PLAN §4.16) — when the order completes or when a shift closes
 * (Inventory setting "Auto-confirm unconfirmed consumption"). They are marked
 * auto-confirmed and listed under Pending Consumption for a manager to review / adjust.
 */
class AutoConfirmConsumption
{
    public function __construct(private ConfirmConsumption $confirm) {}

    /** Every unconfirmed line of a completed order (setting `order_completion`). */
    public function forOrder(Order $order): int
    {
        if (setting('inventory.auto_confirm_consumption', $order->branch_id) !== 'order_completion') {
            return 0;
        }

        return $this->run(static::pending()->where('order_id', $order->id)->get(), $order);
    }

    /**
     * At shift close (setting `shift_close`): unconfirmed lines the kitchen has made, and
     * those of orders already closed.
     */
    public function forBranch(int $branchId): int
    {
        if (setting('inventory.auto_confirm_consumption', $branchId) !== 'shift_close') {
            return 0;
        }

        $items = static::pending()
            ->whereHas('order', fn ($q) => $q->withoutGlobalScope('branch')->where('branch_id', $branchId))
            ->where(fn ($q) => $q
                ->whereIn('kitchen_status', [KitchenStatus::Ready, KitchenStatus::Served])
                ->orWhereHas('order', fn ($o) => $o->withoutGlobalScope('branch')->whereIn('status', [OrderStatus::Completed, OrderStatus::Delivered])))
            ->get();

        return $this->run($items);
    }

    /** Live order lines still waiting for their raw materials to be confirmed. */
    public static function pending(): Builder
    {
        return OrderItem::query()
            ->where('consumption_status', ConsumptionStatus::Pending)
            ->whereNull('voided_at');
    }

    /** @param  Collection<int, OrderItem>  $items */
    private function run(Collection $items, ?Order $order = null): int
    {
        foreach ($items as $item) {
            if ($order) {
                $item->setRelation('order', $order);
            }
            $this->confirm->handle($item, null, null);
        }

        return $items->count();
    }
}
