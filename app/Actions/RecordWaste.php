<?php

namespace App\Actions;

use App\Enums\StockAdjustmentType;
use App\Enums\StockMovementType;
use App\Models\Admin;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\Unit;
use App\Support\Activity;
use App\Support\BusinessDate;
use App\Support\CurrentBranch;
use App\Support\Qty;
use App\Support\StockLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Writes off spoiled / damaged stock (PLAN §4.16): one entry with a reason and one or more
 * lines, each a waste line in the stock ledger valued at the item's average cost.
 */
class RecordWaste
{
    public function __construct(private StockLedger $stock) {}

    /** @param  list<array{item: Model, unit: Unit, quantity: float, stock_quantity: float}>  $lines */
    public function handle(StockAdjustmentType $type, string $reason, array $lines, Admin $admin): StockAdjustment
    {
        return DB::transaction(function () use ($type, $reason, $lines, $admin) {
            $branchId = app(CurrentBranch::class)->id();

            $rows = array_map(fn (array $line) => [
                ...$line,
                'cost' => (float) $line['item']->avg_cost,
                'value' => round($line['stock_quantity'] * (float) $line['item']->avg_cost, 2),
            ], $lines);

            $entry = StockAdjustment::create([
                'branch_id' => $branchId,
                'number' => StockAdjustment::nextNumber($branchId),
                'type' => $type,
                'business_date' => BusinessDate::for($branchId),
                'reason' => $reason,
                'total_cost' => round(array_sum(array_column($rows, 'value')), 2),
                'admin_id' => $admin->id,
            ]);

            $summary = [];
            foreach ($rows as $row) {
                $item = $row['item'];
                $movement = $this->stock->record(
                    $item, StockMovementType::Waste, -$row['stock_quantity'],
                    unitCost: $row['cost'], note: "{$entry->code()} · {$type->label()}: {$reason}", reference: $entry,
                );

                StockAdjustmentItem::create([
                    'stock_adjustment_id' => $entry->id,
                    'stockable_type' => $item->getMorphClass(),
                    'stockable_id' => $item->getKey(),
                    'item_name' => $item->name,
                    'quantity' => $row['quantity'],
                    'unit_id' => $row['unit']->id,
                    'stock_quantity' => $row['stock_quantity'],
                    'unit_cost' => round($row['cost'], 4),
                    'line_cost' => $row['value'],
                    'stock_movement_id' => $movement->id,
                ]);
                $summary[] = Qty::format($row['quantity'])." {$row['unit']->short_name} {$item->name}";
            }

            Activity::log('stock_wasted', $entry, [
                'type' => $type->label(),
                'reason' => $reason,
                'items' => $summary,
                'value' => money($entry->total_cost, $branchId),
            ]);

            return $entry;
        });
    }
}
