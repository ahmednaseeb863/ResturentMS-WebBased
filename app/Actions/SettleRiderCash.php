<?php

namespace App\Actions;

use App\Enums\CashMovementType;
use App\Models\CashMovement;
use App\Models\Delivery;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Shift;
use App\Support\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A rider hands over the COD cash they collected (PLAN §4.14 / §6): one rider settlement
 * into the cashier's open shift (adds to its expected cash) for the chosen deliveries —
 * all the cash the rider holds when none are chosen. The deliveries are marked settled.
 */
class SettleRiderCash
{
    public function __construct(private RecordCashMovement $cash) {}

    /** @param  list<string>|null  $deliveryUuids */
    public function handle(Employee $rider, Shift $shift, ?array $deliveryUuids = null): CashMovement
    {
        return DB::transaction(function () use ($rider, $shift, $deliveryUuids) {
            $deliveries = Delivery::query()
                ->where('rider_id', $rider->id)
                ->unsettled()
                ->when($deliveryUuids, fn ($q) => $q->whereIn('uuid', $deliveryUuids))
                ->lockForUpdate()
                ->get();

            if ($deliveries->isEmpty()) {
                throw ValidationException::withMessages(['rider' => "{$rider->name} holds no cash to settle."]);
            }
            if ($deliveryUuids && $deliveries->count() !== count(array_unique($deliveryUuids))) {
                throw ValidationException::withMessages(['rider' => 'Some of those deliveries are already settled — reload and try again.']);
            }

            $total = round($deliveries->sum(fn (Delivery $d) => (float) $d->cash_collected), 2);
            $codes = Order::query()->whereIn('id', $deliveries->pluck('order_id'))->get()->map->code()->all();

            $movement = $this->cash->handle(
                $shift,
                CashMovementType::RiderSettlement,
                $total,
                "{$rider->name} — ".count($codes).' '.str('delivery')->plural(count($codes)),
                null,
                $rider->id,
            );

            Delivery::query()->whereKey($deliveries->modelKeys())->get()
                ->each(fn (Delivery $d) => $d->forceFill(['settled_at' => now(), 'settlement_movement_id' => $movement->id])->save());

            Activity::log('rider_cash_settled', $rider, [
                'amount' => money($total, $shift->branch_id),
                'orders' => $codes,
                'shift' => $shift->code(),
            ]);

            return $movement;
        });
    }
}
