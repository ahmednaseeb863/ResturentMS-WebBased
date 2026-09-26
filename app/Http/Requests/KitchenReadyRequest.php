<?php

namespace App\Http\Requests;

use App\Models\KitchenTicket;
use App\Models\RawMaterial;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Mark lines of a kitchen ticket ready: `items` (uuids; empty = the whole ticket) and the
 * raw materials the cook confirmed per line — `consumption[].item`, `consumption[].materials[]`
 * with `id` (raw material uuid), `quantity` (in the unit shown) and an optional `reason`.
 */
class KitchenReadyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['nullable', 'array', 'max:100'],
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

    /** @return list<int>|null order item ids on this ticket (null = all) */
    public function itemIds(KitchenTicket $ticket): ?array
    {
        $uuids = $this->validated('items') ?? [];
        if (! $uuids) {
            return null;
        }

        $ids = $ticket->items()->whereIn('uuid', $uuids)->pluck('id')->all();
        if (count($ids) !== count(array_unique($uuids))) {
            throw ValidationException::withMessages(['items' => 'Some of these items are not on this ticket.']);
        }

        return $ids;
    }

    /** @return array<int, array<int, array{material: RawMaterial, quantity: float, reason: ?string}>> */
    public function consumption(KitchenTicket $ticket): array
    {
        $rows = $this->validated('consumption') ?? [];
        if (! $rows) {
            return [];
        }

        $items = $ticket->items()->whereIn('uuid', array_column($rows, 'item'))->pluck('id', 'uuid');
        $materialUuids = collect($rows)->flatMap(fn ($r) => array_column($r['materials'], 'id'))->unique();
        $materials = RawMaterial::query()->whereIn('uuid', $materialUuids)->with('stockUnit', 'purchaseUnit')->get()->keyBy('uuid');

        $out = [];
        foreach ($rows as $i => $row) {
            $itemId = $items[$row['item']] ?? throw ValidationException::withMessages(["consumption.{$i}.item" => 'This item is not on this ticket.']);
            $out[$itemId] = [];

            foreach ($row['materials'] as $j => $line) {
                $material = $materials[$line['id']] ?? throw ValidationException::withMessages(["consumption.{$i}.materials.{$j}.id" => 'Pick a raw material of this branch.']);
                $out[$itemId][$material->id] = [
                    'material' => $material,
                    'quantity' => (float) $line['quantity'],
                    'reason' => filled($line['reason'] ?? null) ? trim($line['reason']) : null,
                ];
            }
        }

        return $out;
    }
}
