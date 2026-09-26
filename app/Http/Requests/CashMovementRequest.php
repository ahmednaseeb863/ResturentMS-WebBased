<?php

namespace App\Http\Requests;

use App\Enums\CashMovementType;
use App\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->shift()->canBeHandledBy($this->user('admin'));
    }

    public function rules(): array
    {
        $manual = array_column(CashMovementType::manualOptions(), 'value');

        return [
            'type' => ['required', Rule::in($manual)],
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999.99', 'decimal:0,2'],
            'reason' => [Rule::requiredIf(fn () => $this->type()?->needsReason()), 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Say why the cash went in or out.'];
    }

    public function shift(): Shift
    {
        return $this->route('shift');
    }

    public function type(): ?CashMovementType
    {
        return CashMovementType::tryFrom((string) $this->input('type'));
    }
}
