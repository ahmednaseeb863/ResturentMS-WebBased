<?php

namespace App\Http\Requests;

use App\Enums\PrinterType;
use App\Models\KitchenStation;
use App\Models\Printer;
use App\Rules\UniqueWithTrash;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class KitchenStationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', new UniqueWithTrash('kitchen_stations', 'name', $this->station()?->id, 'kitchen station', ['branch_id' => app(CurrentBranch::class)->id()])],
            'printer' => ['nullable', 'uuid'],
            'has_screen' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->filled('printer') && ! $this->printer()) {
                $validator->errors()->add('printer', 'Pick an active kitchen printer of this branch.');
            }
        }];
    }

    public function station(): ?KitchenStation
    {
        return $this->route('kitchen_station');
    }

    /** Active kitchen printers of the current branch, or the one already set. */
    public function printer(): ?Printer
    {
        return once(fn () => Printer::query()
            ->where('uuid', $this->input('printer'))
            ->ofType(PrinterType::Kitchen)
            ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $this->station()?->printer_id))
            ->first());
    }

    public function stationData(): array
    {
        return [
            'name' => $this->validated('name'),
            'printer_id' => $this->filled('printer') ? $this->printer()->id : null,
            'has_screen' => $this->boolean('has_screen', true),
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
