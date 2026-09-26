<?php

namespace App\Http\Requests;

use App\Models\OrderItem;
use App\Models\RawMaterial;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Pending consumption review: raw materials per order line (same shape as the kitchen's
 * confirm panel) — `consumption[].item`, `consumption[].materials[] {id, quantity, reason}`.
 * Empty `consumption` with `items` = confirm those lines at the recipe quantities.
 */
class ConsumptionReviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['nullable', 'array', 'max:500'],
            'items.*' => ['uuid'],
            'consumption' => ['nullable', 'array', 'max:100'],
            'consumption.*.item' => ['required', 'uuid'],
            'consumption.*.materials' => ['present', 'array', 'max:50'],
            'consumption.*.materials.*.id' => ['required', 'uuid'],
            'consumption.*.materials.*.quantity' => ['required', 'numeric', 'min:0', 'max:99999'],
            'consumption.*.materials.*.reason' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function attributes(): array
    {
        return ['consumption.*.materials.*.quantity' => 'quantity'];
    }

    /**
     * Order lines of this branch by uuid (the order's branch scope applies).
     *
     * @param  list<string>  $uuids
     * @return Collection<string, OrderItem>
     */
    public static function lines(array $uuids)
    {
        return OrderItem::query()->whereIn('uuid', $uuids)->whereHas('order')->with('order')->get()->keyBy('uuid');
    }

    /** @return array<string, array<int, array{material: RawMaterial, quantity: float, reason: ?string}>> keyed by line uuid, then raw material id */
    public function consumption(): array
    {
        $rows = $this->validated('consumption') ?? [];
        $materialUuids = collect($rows)->flatMap(fn ($r) => array_column($r['materials'], 'id'))->unique();
        $materials = RawMaterial::query()->whereIn('uuid', $materialUuids)->with('stockUnit', 'purchaseUnit')->get()->keyBy('uuid');

        $out = [];
        foreach ($rows as $i => $row) {
            $out[$row['item']] = [];
            foreach ($row['materials'] as $j => $line) {
                $material = $materials[$line['id']] ?? throw ValidationException::withMessages(["consumption.{$i}.materials.{$j}.id" => 'Pick a raw material of this branch.']);
                $out[$row['item']][$material->id] = [
                    'material' => $material,
                    'quantity' => (float) $line['quantity'],
                    'reason' => filled($line['reason'] ?? null) ? trim($line['reason']) : null,
                ];
            }
        }

        return $out;
    }
}
