<?php

namespace App\Http\Requests;

use App\Models\RawMaterial;
use App\Models\ReadyItem;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Quick "add stock" for a raw material or ready item: quantity in any unit it can be
 * counted in (kg / g, or its purchase unit) and the cost of one such unit.
 */
class AddStockRequest extends FormRequest
{
    public const KINDS = ['raw_material' => RawMaterial::class, 'ready_item' => ReadyItem::class];

    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:'.implode(',', array_keys(self::KINDS))],
            'item' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'unit' => ['required', 'uuid'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $item = $this->item();
            if (! $item) {
                $validator->errors()->add('item', 'This item no longer exists — reload the page.');
            } elseif (! $this->unit() || ! $item->acceptsUnit($this->unit())) {
                $validator->errors()->add('unit', "Pick a unit {$item->name} is counted or bought in.");
            }
        }];
    }

    /** Raw material / ready item of the current branch. */
    public function item(): ?Model
    {
        return once(fn () => self::KINDS[$this->input('kind')]::query()
            ->with('stockUnit', 'purchaseUnit')
            ->where('uuid', $this->input('item'))
            ->first());
    }

    public function unit(): ?Unit
    {
        return once(fn () => Unit::query()->where('uuid', $this->input('unit'))->first());
    }

    /** Quantity in the item's stock unit. */
    public function stockQuantity(): float
    {
        return round($this->item()->toStockQuantity((float) $this->validated('quantity'), $this->unit()), 3);
    }

    /** Cost of one stock unit (the entered cost is per entered unit). */
    public function stockUnitCost(): ?float
    {
        if (! $this->filled('unit_cost')) {
            return null;
        }

        $stockQty = $this->stockQuantity();

        return $stockQty > 0 ? (float) $this->validated('unit_cost') * (float) $this->validated('quantity') / $stockQty : null;
    }
}
