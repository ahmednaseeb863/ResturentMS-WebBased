<?php

namespace App\Http\Requests\Concerns;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Validator;

/**
 * Stock fields shared by raw materials and ready items: stock unit, optional purchase
 * unit (+ how many stock units it holds), alert level, and — when adding — opening
 * stock and cost. The stock unit is locked once stock has been recorded.
 */
trait ValidatesStockUnits
{
    /** @var array<string, Unit|null> */
    private array $unitCache = [];

    /** The raw material / ready item being edited (null when adding). */
    abstract public function stockItem(): ?Model;

    protected function stockRules(): array
    {
        return [
            'stock_unit' => ['required', 'uuid'],
            'purchase_unit' => ['nullable', 'uuid'],
            'purchase_unit_factor' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'alert_level' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'opening_stock' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }

    protected function checkStockUnits(Validator $validator): void
    {
        $stock = $this->unitByUuid('stock_unit');
        $purchase = $this->unitByUuid('purchase_unit');
        $item = $this->stockItem();

        if (! $stock) {
            $validator->errors()->add('stock_unit', 'Pick a unit.');

            return;
        }

        if ($item && $item->stock_unit_id !== $stock->id && $item->stockMovements()->exists()) {
            $validator->errors()->add('stock_unit', 'Stock is already recorded in '.$item->stockUnit->short_name.' — the stock unit cannot change.');
        }

        if ($this->filled('purchase_unit')) {
            if (! $purchase) {
                $validator->errors()->add('purchase_unit', 'Pick a unit.');
            } elseif ($purchase->id === $stock->id) {
                $validator->errors()->add('purchase_unit', 'Leave empty when it is bought in the stock unit.');
            } elseif (! $purchase->sameFamily($stock) && ! $this->filled('purchase_unit_factor')) {
                $validator->errors()->add('purchase_unit_factor', "How many {$stock->short_name} are in one {$purchase->short_name}?");
            }
        }
    }

    /** stock_unit_id, purchase_unit_id, purchase_unit_factor (worked out for kg ↔ g), alert_level */
    public function stockData(): array
    {
        $stock = $this->unitByUuid('stock_unit');
        $purchase = $this->filled('purchase_unit') ? $this->unitByUuid('purchase_unit') : null;

        $factor = match (true) {
            $purchase === null => null,
            $purchase->sameFamily($stock) => round(Unit::convert(1, $purchase, $stock), 3),
            default => (float) $this->validated('purchase_unit_factor'),
        };

        return [
            'stock_unit_id' => $stock->id,
            'purchase_unit_id' => $purchase?->id,
            'purchase_unit_factor' => $factor,
            'alert_level' => $this->filled('alert_level') ? $this->validated('alert_level') : null,
        ];
    }

    /** Opening stock and cost per stock unit — only used when adding. */
    public function opening(): array
    {
        return [
            'quantity' => (float) ($this->validated('opening_stock') ?? 0),
            'unit_cost' => $this->filled('unit_cost') ? (float) $this->validated('unit_cost') : null,
        ];
    }

    private function unitByUuid(string $field): ?Unit
    {
        $uuid = $this->input($field);

        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        return $this->unitCache[$uuid] ??= Unit::query()->where('uuid', $uuid)->first();
    }
}
