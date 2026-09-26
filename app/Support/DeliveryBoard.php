<?php

namespace App\Support;

use App\Enums\DeliveryStatus;
use App\Enums\DesignationType;
use App\Enums\KitchenStatus;
use App\Models\Delivery;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared queries of the deliveries board, riders screen and rider panel (PLAN §4.14).
 */
class DeliveryBoard
{
    /** Eager loads for DeliveryResource. */
    public static function with(): array
    {
        return [
            'order' => fn ($q) => $q->withCount(['items as cooking_count' => fn ($i) => $i->whereNull('voided_at')
                ->whereIn('kitchen_status', [KitchenStatus::Pending, KitchenStatus::Preparing])]),
            'order.customer',
            'order.lines',
            'zone',
            'rider',
            'assignedBy',
        ];
    }

    /** Deliveries of placed, not cancelled orders. */
    public static function query(): Builder
    {
        return Delivery::query()
            ->whereHas('order', fn ($q) => $q->where('status', '!=', 'draft'))
            ->where('status', '!=', DeliveryStatus::Cancelled);
    }

    /**
     * Active riders of the branch with what they are doing: deliveries in hand, delivered
     * today, cash held.
     *
     * @return list<array{id: string, name: string, phone: ?string, active: int, delivered_today: int, cash_held: float, has_login: bool}>
     */
    public static function riders(): array
    {
        $riders = Employee::query()->ofType(DesignationType::Rider)->orderBy('name')->get();
        if ($riders->isEmpty()) {
            return [];
        }

        $ids = $riders->modelKeys();
        $active = Delivery::query()->whereIn('rider_id', $ids)
            ->whereIn('status', [DeliveryStatus::Assigned, DeliveryStatus::OutForDelivery, DeliveryStatus::Failed])
            ->toBase()->selectRaw('rider_id, count(*) as n')->groupBy('rider_id')->pluck('n', 'rider_id');
        $cash = Delivery::query()->whereIn('rider_id', $ids)->unsettled()
            ->toBase()->selectRaw('rider_id, sum(cash_collected) as total')->groupBy('rider_id')->pluck('total', 'rider_id');
        $today = Delivery::query()->whereIn('rider_id', $ids)->where('status', DeliveryStatus::Delivered)
            ->whereHas('order', fn ($q) => $q->where('business_date', BusinessDate::for()))
            ->toBase()->selectRaw('rider_id, count(*) as n')->groupBy('rider_id')->pluck('n', 'rider_id');

        return $riders->map(fn (Employee $r) => [
            'id' => $r->uuid,
            'name' => $r->name,
            'phone' => $r->phone,
            'active' => (int) ($active[$r->id] ?? 0),
            'delivered_today' => (int) ($today[$r->id] ?? 0),
            'cash_held' => round((float) ($cash[$r->id] ?? 0), 2),
            'has_login' => $r->admin_id !== null,
        ])->all();
    }
}
