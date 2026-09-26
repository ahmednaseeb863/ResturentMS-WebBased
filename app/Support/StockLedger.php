<?php

namespace App\Support;

use App\Enums\StockMovementType;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way stock changes (PLAN §7 Inventory). In one transaction it locks the
 * raw material / ready item row, writes the ledger line and updates `current_stock`
 * (and the average cost on stock-in), so the ledger and the balance always match.
 *
 *   app(StockLedger::class)->record($chicken, StockMovementType::StockIn, 12.5, unitCost: 880);
 *
 * `quantity` is signed and in the item's stock unit; `unitCost` is per stock unit.
 * `allowNegative` overrides `inventory.allow_negative_stock` (POS sales follow the
 * "ready item out of stock" setting instead).
 */
class StockLedger
{
    public function record(
        Model $item,
        StockMovementType $type,
        float $quantity,
        ?float $unitCost = null,
        ?string $note = null,
        ?Model $reference = null,
        ?bool $allowNegative = null,
    ): StockMovement {
        return DB::transaction(function () use ($item, $type, $quantity, $unitCost, $note, $reference, $allowNegative) {
            $locked = $item->newQueryWithoutScopes()->lockForUpdate()->findOrFail($item->getKey());

            $before = (float) $locked->current_stock;
            $after = round($before + $quantity, 3);

            $allowNegative ??= (bool) setting('inventory.allow_negative_stock', $locked->branch_id);

            if ($quantity < 0 && $after < 0 && ! $allowNegative) {
                throw ValidationException::withMessages([
                    'quantity' => "Not enough stock of “{$locked->name}” — only ".Qty::format($before).' left.',
                ]);
            }

            $changes = ['current_stock' => $after];

            if ($quantity > 0 && $unitCost !== null) {
                $changes['avg_cost'] = $before > 0
                    ? round(($before * (float) $locked->avg_cost + $quantity * $unitCost) / $after, 4)
                    : round($unitCost, 4);
            }

            $locked->forceFill($changes)->saveQuietly(); // the ledger line is the history
            $item->forceFill($locked->only(array_keys($changes)))->syncOriginalAttributes(array_keys($changes));

            return StockMovement::create([
                'branch_id' => $locked->branch_id,
                'stockable_type' => $locked->getMorphClass(),
                'stockable_id' => $locked->getKey(),
                'business_date' => BusinessDate::for($locked->branch_id),
                'type' => $type,
                'quantity' => round($quantity, 3),
                'unit_cost' => $unitCost === null ? null : round($unitCost, 4),
                'balance_after' => $after,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'note' => $note,
                'admin_id' => Auth::guard('admin')->id(),
            ]);
        });
    }
}
