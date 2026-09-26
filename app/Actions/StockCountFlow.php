<?php

namespace App\Actions;

use App\Enums\StockCountStatus;
use App\Enums\StockMovementType;
use App\Models\Admin;
use App\Models\RawMaterial;
use App\Models\ReadyItem;
use App\Models\StockCount;
use App\Support\Activity;
use App\Support\BusinessDate;
use App\Support\CurrentBranch;
use App\Support\Qty;
use App\Support\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock counts (PLAN §4.16): start (the system quantity of each item is noted now) →
 * enter what was counted (save as often as needed) → submit → a manager approves: every
 * difference becomes a count correction in the ledger. Uncounted lines are left alone.
 * A count can be cancelled until it is approved.
 */
class StockCountFlow
{
    public function __construct(private StockLedger $stock) {}

    /** @param  'all'|'raw_material'|'ready_item'  $kind */
    public function start(string $kind, ?int $categoryId, ?string $notes, Admin $admin): StockCount
    {
        return DB::transaction(function () use ($kind, $categoryId, $notes, $admin) {
            $branchId = app(CurrentBranch::class)->id();

            $items = collect();
            if ($kind !== 'ready_item') {
                $items = $items->concat(RawMaterial::query()->active()->with('category')
                    ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))->lockForUpdate()->get());
            }
            if ($kind !== 'raw_material' && ! $categoryId) {
                $items = $items->concat(ReadyItem::query()->active()->lockForUpdate()->get());
            }
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['kind' => 'There is nothing to count there.']);
            }

            $scope = match ($kind) {
                'raw_material' => 'Raw materials'.($categoryId ? ' · '.$items->first()->category?->name : ''),
                'ready_item' => 'Ready items',
                default => 'All stock',
            };

            $count = StockCount::create([
                'branch_id' => $branchId,
                'number' => StockCount::nextNumber($branchId),
                'status' => StockCountStatus::Draft,
                'scope' => $scope,
                'business_date' => BusinessDate::for($branchId),
                'notes' => $notes,
                'created_by' => $admin->id,
            ]);

            foreach ($items as $item) {
                $count->items()->create([
                    'stockable_type' => $item->getMorphClass(),
                    'stockable_id' => $item->getKey(),
                    'item_name' => $item->name,
                    'system_qty' => $item->current_stock,
                    'unit_cost' => $item->avg_cost,
                ]);
            }

            Activity::log('stock_count_started', $count, ['scope' => $scope, 'items' => $items->count()]);

            return $count;
        });
    }

    /** @param  array<string, ?float>  $counted  count line uuid => counted quantity (null = not counted) */
    public function save(StockCount $count, array $counted): void
    {
        DB::transaction(function () use ($count, $counted) {
            $count = $this->lock($count, [StockCountStatus::Draft]);
            $lines = $count->items()->whereIn('uuid', array_keys($counted))->get();

            foreach ($lines as $line) {
                $value = $counted[$line->uuid];
                $line->update(['counted_qty' => $value === null ? null : round($value, 3)]);
            }
        });
    }

    public function submit(StockCount $count, Admin $admin): StockCount
    {
        return DB::transaction(function () use ($count, $admin) {
            $count = $this->lock($count, [StockCountStatus::Draft]);
            if (! $count->items()->whereNotNull('counted_qty')->exists()) {
                throw ValidationException::withMessages(['count' => 'Enter at least one counted quantity.']);
            }

            $count->update(['status' => StockCountStatus::Submitted, 'submitted_by' => $admin->id, 'submitted_at' => now()]);
            Activity::log('stock_count_submitted', $count);

            return $count;
        });
    }

    /** Corrections for every counted difference, at the average cost noted when counting. */
    public function approve(StockCount $count, Admin $admin): StockCount
    {
        return DB::transaction(function () use ($count, $admin) {
            $count = $this->lock($count, [StockCountStatus::Submitted]);
            $lines = $count->items()->with('stockable')->whereNotNull('counted_qty')->get();

            $value = 0.0;
            $changes = [];
            foreach ($lines as $line) {
                $variance = $line->variance();
                if (! $variance || ! $line->stockable) {
                    continue;
                }

                $movement = $this->stock->record(
                    $line->stockable, StockMovementType::CountCorrection, $variance,
                    unitCost: (float) $line->unit_cost, note: "{$count->code()} · counted ".Qty::format($line->counted_qty),
                    reference: $count, allowNegative: true,
                );
                $line->update(['stock_movement_id' => $movement->id]);

                $value += $variance * (float) $line->unit_cost;
                $changes[] = "{$line->item_name} ".($variance > 0 ? '+' : '').Qty::format($variance);
            }

            $count->update([
                'status' => StockCountStatus::Approved,
                'approved_by' => $admin->id,
                'approved_at' => now(),
                'variance_value' => round($value, 2),
            ]);
            Activity::log('stock_count_approved', $count, ['corrections' => $changes ?: null, 'value' => money($value, $count->branch_id)]);

            return $count;
        });
    }

    public function cancel(StockCount $count): StockCount
    {
        return DB::transaction(function () use ($count) {
            $count = $this->lock($count, [StockCountStatus::Draft, StockCountStatus::Submitted]);
            $count->update(['status' => StockCountStatus::Cancelled]);
            Activity::log('stock_count_cancelled', $count);

            return $count;
        });
    }

    /** @param  list<StockCountStatus>  $allowed */
    private function lock(StockCount $count, array $allowed): StockCount
    {
        $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);
        if (! in_array($count->status, $allowed, true)) {
            throw ValidationException::withMessages(['count' => "{$count->code()} is {$count->status->label()}."]);
        }

        return $count;
    }
}
