<?php

namespace App\Http\Requests;

use App\Rules\UniqueWithTrash;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;

/** End at or before start = overnight (e.g. 19:00–04:00). Equal times are not allowed. */
class ShiftTypeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', new UniqueWithTrash('shift_types', 'name', $this->route('shift_type')?->id, 'shift type', ['branch_id' => app(CurrentBranch::class)->id()])],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return ['end_time.different' => 'The shift must end at a different time than it starts.'];
    }

    public function shiftTypeData(): array
    {
        return [
            'name' => $this->validated('name'),
            'start_time' => $this->validated('start_time'),
            'end_time' => $this->validated('end_time'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
