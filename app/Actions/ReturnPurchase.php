<?php

namespace App\Actions;

use App\Enums\StockMovementType;
use App\Models\Admin;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Support\Activity;
use App\Support\BusinessDate;
use App\Support\Qty;
use App\Support\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends goods of a purchase back to the supplier (PLAN §4.16): stock out at the line's
 * cost (purchase return in the ledger), the purchase bill goes down by the value returned.
 * Never more than was received and not yet returned.
 */
class ReturnPurchase
{
    public function __construct(private StockLedger $stock) {}

    /** @param  array<string, float>  $quantities  purchase line uuid => quantity in the line's unit */
    public function handle(Purchase $purchase, array $quantities, string $reason, Admin $admin): PurchaseReturn
    {
        return DB::transaction(function () use ($purchase, $quantities, $reason, $admin) {
            $purchase = Purchase::query()->with('supplier')->lockForUpdate()->findOrFail($purchase->id);
            $lines = $purchase->items()->with('stockable.stockUnit', 'unit')->lockForUpdate()->get()->keyBy('uuid');
            $quantities = array_filter($quantities, fn ($q) => (float) $q > 0);

            if (! $quantities) {
                throw ValidationException::withMessages(['lines' => 'Enter what goes back.']);
            }

            // work out every line first — the return is written once, with its total
            $rows = [];
            foreach ($quantities as $uuid => $quantity) {
                /** @var PurchaseItem|null $line */
                $line = $lines->get($uuid) ?? throw ValidationException::withMessages(['lines' => 'That line is not on this purchase.']);
                $quantity = round((float) $quantity, 3);
                $perUnit = (float) $line->stock_quantity / max((float) $line->quantity, 0.001); // stock units in one entered unit
                $stockQty = round($quantity * $perUnit, 3);

                if ($stockQty > $line->returnable() + 0.0005) {
                    $left = round($line->returnable() / $perUnit, 3);
                    throw ValidationException::withMessages(["lines.{$uuid}" => 'Only '.Qty::format($left)." {$line->unit->short_name} of {$line->item_name} can go back."]);
                }

                $rows[] = ['line' => $line, 'quantity' => $quantity, 'stock' => $stockQty, 'value' => round($stockQty * $line->stockUnitCost(), 2)];
            }
            $total = round(array_sum(array_column($rows, 'value')), 2);

            $return = PurchaseReturn::create([
                'branch_id' => $purchase->branch_id,
                'number' => PurchaseReturn::nextNumber($purchase->branch_id),
                'purchase_id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'business_date' => BusinessDate::for($purchase->branch_id),
                'reason' => $reason,
                'total' => $total,
                'admin_id' => $admin->id,
            ]);

            $summary = [];
            foreach ($rows as ['line' => $line, 'quantity' => $quantity, 'stock' => $stockQty, 'value' => $value]) {
                $movement = $line->stockable
                    ? $this->stock->record($line->stockable, StockMovementType::PurchaseReturn, -$stockQty,
                        unitCost: $line->stockUnitCost(), note: "{$return->code()} · {$purchase->code()} · {$purchase->supplier->name}", reference: $return)
                    : null;

                PurchaseReturnItem::create([
                    'purchase_return_id' => $return->id,
                    'purchase_item_id' => $line->id,
                    'quantity' => $quantity,
                    'stock_quantity' => $stockQty,
                    'line_total' => $value,
                    'stock_movement_id' => $movement?->id,
                ]);
                $line->update(['returned_stock_quantity' => round((float) $line->returned_stock_quantity + $stockQty, 3)]);
                $summary[] = Qty::format($quantity)." {$line->unit->short_name} {$line->item_name}";
            }

            $purchase->returned_total = round((float) $purchase->returned_total + $total, 2);
            $purchase->syncPaymentStatus();
            $purchase->save();

            Activity::log('purchase_returned', $purchase, [
                'return' => $return->code(),
                'value' => money($total, $purchase->branch_id),
                'items' => $summary,
                'reason' => $reason,
            ]);

            return $return;
        });
    }
}
