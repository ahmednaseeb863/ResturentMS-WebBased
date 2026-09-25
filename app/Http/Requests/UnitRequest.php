<?php

namespace App\Http\Requests;

use App\Models\Unit;
use App\Rules\UniqueWithTrash;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A unit is a base unit (no `base_unit`) or part of one: `factor` base units in one
 * of it (1 g = 0.001 kg). The conversion of a unit already in use cannot change.
 */
class UnitRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:40'],
            'short_name' => ['required', 'string', 'max:12', new UniqueWithTrash('units', 'short_name', $this->unit()?->id, 'unit')],
            'base_unit' => ['nullable', 'uuid'],
            'factor' => ['required_with:base_unit', 'nullable', 'numeric', 'gt:0', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return ['factor.required_with' => 'How many base units is one of it?'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $unit = $this->unit();
            $base = $this->baseUnit();

            if ($this->filled('base_unit')) {
                if (! $base || $base->base_unit_id !== null) {
                    $validator->errors()->add('base_unit', 'Pick a base unit (kg, L, pcs…).');
                } elseif ($unit && $base->id === $unit->id) {
                    $validator->errors()->add('base_unit', 'A unit cannot be part of itself.');
                } elseif ($unit?->derivedUnits()->exists()) {
                    $validator->errors()->add('base_unit', 'Other units are part of this one, so it must stay a base unit.');
                }
            }

            if ($unit && $validator->errors()->isEmpty()) {
                $data = $this->unitData();
                $changed = $data['base_unit_id'] !== $unit->base_unit_id || abs($data['factor'] - $unit->factor) > 1e-9;
                $uses = $changed ? $unit->usage() : [];

                if ($uses) {
                    $validator->errors()->add('base_unit', 'Already used by '.implode(', ', $uses).' — its conversion cannot change. Add a new unit instead.');
                }
            }
        }];
    }

    public function unit(): ?Unit
    {
        return $this->route('unit');
    }

    public function baseUnit(): ?Unit
    {
        return $this->filled('base_unit') ? once(fn () => Unit::query()->where('uuid', $this->input('base_unit'))->first()) : null;
    }

    public function unitData(): array
    {
        $base = $this->baseUnit();

        return [
            'name' => $this->input('name'),
            'short_name' => $this->input('short_name'),
            'base_unit_id' => $base?->id,
            'factor' => $base ? (float) $this->input('factor') : 1.0,
        ];
    }
}
