<?php

namespace App\Actions;

use App\Enums\StockMovementType;
use App\Support\StockLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Saves a raw material / ready item. When adding, the opening stock goes through the
 * ledger (an "Opening stock" line); a cost without stock just sets the average cost
 * so recipes can be costed. After that, stock only changes through recorded movements.
 */
class SaveStockItem
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * @param  array{quantity: float, unit_cost: ?float}  $opening
     */
    public function handle(Model $item, array $data, array $opening = ['quantity' => 0, 'unit_cost' => null]): Model
    {
        return DB::transaction(function () use ($item, $data, $opening) {
            $isNew = ! $item->exists;
            $item->fill($data)->save();

            if (! $isNew) {
                return $item;
            }

            if ($opening['quantity'] > 0) {
                $this->ledger->record($item, StockMovementType::Opening, $opening['quantity'], $opening['unit_cost'] ?? 0);
            } elseif ($opening['unit_cost'] !== null) {
                $item->forceFill(['avg_cost' => round($opening['unit_cost'], 4)])->saveQuietly();
            }

            return $item;
        });
    }
}
