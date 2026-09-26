<?php

namespace App\Http\Requests\Concerns;

use App\Http\Requests\AddStockRequest;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Validator;

/**
 * Stock lines sent by a form (purchases, waste): `lines.*.kind` raw_material / ready_item,
 * `item` uuid, `quantity` in `unit` (any unit the item is counted or bought in) and
 * optionally `unit_cost` per that unit. `stockLines()` gives the models and the quantity
 * in the item's stock unit.
 */
trait ReadsStockLines
{
    protected function stockLineRules(bool $withCost): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.kind' => ['required', 'in:'.implode(',', array_keys(AddStockRequest::KINDS))],
            'lines.*.item' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'lines.*.unit' => ['required', 'uuid'],
            'lines.*.unit_cost' => $withCost ? ['required', 'numeric', 'min:0', 'max:99999999'] : ['nullable'],
        ];
    }

    protected function stockLineMessages(): array
    {
        return [
            'lines.required' => 'Add at least one item.',
            'lines.*.item.required' => 'Pick the item.',
            'lines.*.quantity.required' => 'Enter the quantity.',
            'lines.*.quantity.gt' => 'Enter the quantity.',
            'lines.*.unit_cost.required' => 'Enter the cost.',
        ];
    }

    /** Check every line's item (this branch) and unit; call from after(). */
    protected function checkStockLines(Validator $validator): void
    {
        foreach ($this->stockLines() as $i => $line) {
            if (! $line['item']) {
                $validator->errors()->add("lines.{$i}.item", 'This item no longer exists — pick again.');
            } elseif (! $line['unit'] || ! $line['item']->acceptsUnit($line['unit'])) {
                $validator->errors()->add("lines.{$i}.unit", "Pick a unit {$line['item']->name} is counted or bought in.");
            }
        }
    }

    /**
     * @return list<array{item: ?Model, unit: ?Unit, quantity: float, unit_cost: ?float, stock_quantity: float}>
     */
    public function stockLines(): array
    {
        return once(function () {
            $lines = (array) $this->input('lines', []);
            $units = Unit::query()->whereIn('uuid', array_column($lines, 'unit'))->get()->keyBy('uuid');
            $items = [];
            foreach (AddStockRequest::KINDS as $kind => $class) {
                $uuids = array_column(array_filter($lines, fn ($l) => ($l['kind'] ?? null) === $kind), 'item');
                $items[$kind] = $uuids ? $class::query()->with('stockUnit', 'purchaseUnit')->whereIn('uuid', $uuids)->get()->keyBy('uuid') : collect();
            }

            return array_values(array_map(function (array $line) use ($units, $items) {
                $item = ($items[$line['kind'] ?? ''] ?? collect())->get($line['item'] ?? '');
                $unit = $units->get($line['unit'] ?? '');
                $quantity = round((float) ($line['quantity'] ?? 0), 3);

                return [
                    'item' => $item,
                    'unit' => $unit,
                    'quantity' => $quantity,
                    'unit_cost' => isset($line['unit_cost']) && $line['unit_cost'] !== '' ? (float) $line['unit_cost'] : null,
                    'stock_quantity' => $item && $unit && $item->acceptsUnit($unit) ? round($item->toStockQuantity($quantity, $unit), 3) : 0.0,
                ];
            }, $lines));
        });
    }
}
