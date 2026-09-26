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
use App\Support\RecipeUse;
use App\Support\StockLedger;
use Illuminate\Validation\ValidationException;

/**
 * Deducts the raw materials an order line used (PLAN §4.16 "cook-verified"). The cook
 * confirms the recipe quantities or changes them (and may add a material that isn't in
 * the recipe); `null` = auto-confirm at the recipe quantities. Expected and actual are
 * both kept for the variance report. The food is already made, so stock may go negative.
 * Call inside the caller's transaction.
 */
class ConfirmConsumption
{
    public function __construct(private StockLedger $stock) {}

    /**
     * @param  array<int, array{material: RawMaterial, quantity: float, reason: ?string}>|null  $actual  keyed by raw material id
     */
    public function handle(OrderItem $item, ?array $actual, Admin $admin): void
    {
        if ($item->consumption_status !== ConsumptionStatus::Pending || $item->isVoided()) {
            return;
        }

        $expected = RecipeUse::of($item);
        $auto = $actual === null;
        $actual ??= [];

        $rows = $expected->map(fn ($use, $id) => [
            'material' => $use['material'],
            'unit' => $use['unit'],
            'expected' => $use['quantity'],
            'actual' => isset($actual[$id]) ? round((float) $actual[$id]['quantity'], 3) : $use['quantity'],
            'reason' => $actual[$id]['reason'] ?? null,
        ])->values();

        // materials the cook added that aren't in the recipe (in their stock unit)
        foreach (array_diff_key($actual, $expected->all()) as $line) {
            $line['material']->loadMissing('stockUnit');
            $rows->push([
                'material' => $line['material'],
                'unit' => $line['material']->stockUnit,
                'expected' => 0.0,
                'actual' => round((float) $line['quantity'], 3),
                'reason' => $line['reason'] ?? null,
            ]);
        }

        $item->loadMissing('order');
        $date = BusinessDate::for($item->order->branch_id);
        $note = "Order {$item->order->code()} · {$item->quantity} × {$item->fullName()}";

        foreach ($rows as $row) {
            if ($row['actual'] < 0) {
                throw ValidationException::withMessages(['consumption' => "{$row['material']->name} can't be below zero."]);
            }
            if ($row['actual'] == 0 && $row['expected'] == 0) {
                continue;
            }

            $movement = null;
            if ($row['actual'] > 0) {
                $material = $row['material'];
                $material->loadMissing('stockUnit', 'purchaseUnit');
                $movement = $this->stock->record(
                    $material, StockMovementType::Consumption, -$material->toStockQuantity($row['actual'], $row['unit']),
                    unitCost: (float) $material->avg_cost, note: $note, reference: $item, allowNegative: true,
                );
            }

            OrderItemConsumption::create([
                'branch_id' => $item->order->branch_id,
                'order_item_id' => $item->id,
                'raw_material_id' => $row['material']->id,
                'unit_id' => $row['unit']->id,
                'business_date' => $date,
                'expected_qty' => $row['expected'],
                'actual_qty' => $row['actual'],
                'reason' => $row['actual'] != $row['expected'] ? $row['reason'] : null,
                'auto' => $auto,
                'confirmed_by' => $auto ? null : $admin->id,
                'confirmed_at' => now(),
                'stock_movement_id' => $movement?->id,
            ]);
        }

        $item->update(['consumption_status' => $auto ? ConsumptionStatus::AutoConfirmed : ConsumptionStatus::Confirmed]);
    }

    /** The recipe quantities the cook sees for a line (in the recipe's unit). */
    public static function plan(OrderItem $item): array
    {
        return RecipeUse::of($item)->map(fn ($use) => [
            'id' => $use['material']->uuid,
            'name' => $use['material']->name,
            'unit' => $use['unit']->short_name,
            'expected' => $use['quantity'],
            'expected_text' => Qty::format($use['quantity']).' '.$use['unit']->short_name,
        ])->values()->all();
    }
}
