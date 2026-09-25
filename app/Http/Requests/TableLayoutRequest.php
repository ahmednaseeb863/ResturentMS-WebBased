<?php

namespace App\Http\Requests;

use App\Models\Area;
use App\Models\DiningTable;
use App\Support\FloorPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/** New positions of the tables of one area, from the floor-plan editor. */
class TableLayoutRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'area' => ['required', 'uuid'],
            'tables' => ['required', 'array', 'min:1', 'max:200'],
            'tables.*.id' => ['required', 'uuid', 'distinct'],
            'tables.*.x' => ['required', 'integer', 'min:0', 'max:'.(DiningTable::GRID_COLS - 1)],
            'tables.*.y' => ['required', 'integer', 'min:0', 'max:'.(DiningTable::GRID_ROWS - 1)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (! $this->area()) {
                $validator->errors()->add('area', 'Pick an area of this branch.');

                return;
            }
            if ($this->tables()->count() !== count($this->input('tables'))) {
                $validator->errors()->add('tables', 'A table is not in this area any more — reload the page.');

                return;
            }

            // moved tables must fit and not overlap the tables that stay or each other
            // (overlaps between tables that are not moved are left alone)
            $moved = $this->positions();
            $placed = FloorPlan::placed($this->area()->id)
                ->reject(fn ($rect) => $this->tables()->has($rect['id']))
                ->values();

            foreach ($this->tables() as $table) {
                [$x, $y] = $moved[$table->uuid];

                $rect = FloorPlan::rect($table, $x, $y);
                if ($rect['x'] + $rect['w'] > DiningTable::GRID_COLS || $rect['y'] + $rect['h'] > DiningTable::GRID_ROWS) {
                    $validator->errors()->add('tables', "“{$table->name}” does not fit there.");

                    return;
                }
                if ($other = FloorPlan::hits($placed, $rect['x'], $rect['y'], $rect['w'], $rect['h'])) {
                    $validator->errors()->add('tables', "“{$table->name}” overlaps “{$other}”.");

                    return;
                }
                $placed->push($rect);
            }
        }];
    }

    public function area(): ?Area
    {
        return once(fn () => Area::query()->where('uuid', $this->input('area'))->first());
    }

    /** The moved tables (of this area and branch), keyed by uuid. */
    public function tables(): Collection
    {
        return once(fn () => DiningTable::query()
            ->where('area_id', $this->area()->id)
            ->whereIn('uuid', collect($this->input('tables'))->pluck('id'))
            ->get()->keyBy('uuid'));
    }

    /** @return array<string, array{0: int, 1: int}> uuid => [x, y] */
    public function positions(): array
    {
        return collect($this->input('tables'))
            ->mapWithKeys(fn ($t) => [$t['id'] => [(int) $t['x'], (int) $t['y']]])
            ->all();
    }
}
