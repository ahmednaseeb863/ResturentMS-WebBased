<?php

namespace App\Actions;

use App\Enums\ConsumptionStatus;
use App\Enums\StockMovementType;
use App\Models\Admin;
use App\Models\OrderItem;
use App\Models\OrderItemConsumption;
use App\Models\RawMaterial;
use App\Support\BusinessDate;
use App\Support\Qty;
use App\Support\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A manager corrects the raw materials a confirmed / auto-confirmed line used (PLAN §4.16
 * "Pending consumption … review and adjust"). Consumption rows are append-only, so each
 * change is a new correction row (expected 0, actual = the difference) with its stock
 * movement; the expected total stays as the recipe said.
 */
class AdjustConsumption
{
    public function __construct(private StockLedger $stock) {}

    /** @param  array<int, array{material: RawMaterial, quantity: float, reason: ?string}>  $actual  keyed by raw material id — the new total used */
    public function handle(OrderItem $item, array $actual, Admin $admin): int
    {
        return DB::transaction(function () use ($item, $actual, $admin) {
            $item = OrderItem::query()->with('order')->lockForUpdate()->findOrFail($item->id);
            if (! in_array($item->consumption_status, [ConsumptionStatus::Confirmed, ConsumptionStatus::AutoConfirmed], true)) {
                throw ValidationException::withMessages(['consumption' => 'Confirm the raw materials of this line first.']);
            }

            $used = OrderItemConsumption::query()->where('order_item_id', $item->id)->with('unit')->get()->groupBy('raw_material_id');
            $date = BusinessDate::for($item->order->branch_id);
            $changes = 0;

            foreach ($actual as $materialId => $line) {
                $rows = $used->get($materialId);
                $before = $rows ? round((float) $rows->sum('actual_qty'), 3) : 0.0;
                $diff = round((float) $line['quantity'] - $before, 3);
                if ($diff == 0) {
                    continue;
                }
                if (blank($line['reason'])) {
                    throw ValidationException::withMessages(['consumption' => "Say why {$line['material']->name} changes."]);
                }

                $material = $line['material'];
                $material->loadMissing('stockUnit', 'purchaseUnit');
                $unit = $rows?->first()->unit ?? $material->stockUnit;

                $movement = $this->stock->record(
                    $material, StockMovementType::Consumption, -$material->toStockQuantity($diff, $unit),
                    unitCost: (float) $material->avg_cost,
                    note: "Correction · order {$item->order->code()} · {$item->fullName()} (".($diff > 0 ? '+' : '').Qty::format($diff)." {$unit->short_name})",
                    reference: $item, allowNegative: true,
                );

                OrderItemConsumption::create([
                    'branch_id' => $item->order->branch_id,
                    'order_item_id' => $item->id,
                    'raw_material_id' => $material->id,
                    'unit_id' => $unit->id,
                    'business_date' => $date,
                    'expected_qty' => 0,
                    'actual_qty' => $diff,
                    'reason' => $line['reason'],
                    'auto' => false,
                    'confirmed_by' => $admin->id,
                    'confirmed_at' => now(),
                    'stock_movement_id' => $movement->id,
                ]);
                $changes++;
            }

            if ($changes === 0) {
                throw ValidationException::withMessages(['consumption' => 'Nothing changed.']);
            }

            return $changes;
        });
    }
}
