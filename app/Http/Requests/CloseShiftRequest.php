<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReadsCashCount;
use App\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CloseShiftRequest extends FormRequest
{
    use ReadsCashCount;

    public function authorize(): bool
    {
        return $this->shift()->canBeHandledBy($this->user('admin'));
    }

    public function rules(): array
    {
        return [
            'counted_cash' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            ...$this->countRules(),
            'float_left' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:500'],
            'pin' => ['nullable', 'digits_between:4,6'],
        ];
    }

    public function messages(): array
    {
        return ['pin.digits_between' => 'A PIN is 4 to 6 digits.'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->checkDenominations($validator);

            if (setting('shifts.require_denominations') && ! $this->hasCount()) {
                $validator->errors()->add('count', 'Count the notes and coins in the drawer.');
            } elseif (! $this->hasCount() && ! $this->filled('counted_cash')) {
                $validator->errors()->add('counted_cash', 'Enter the cash counted in the drawer.');
            } elseif ((float) $this->input('float_left') > $this->countedCash()) {
                $validator->errors()->add('float_left', 'The float left cannot be more than the cash counted.');
            }
        }];
    }

    public function shift(): Shift
    {
        return $this->route('shift');
    }

    public function countedCash(): float
    {
        return $this->hasCount() ? $this->countTotal() : round((float) $this->input('counted_cash'), 2);
    }
}
