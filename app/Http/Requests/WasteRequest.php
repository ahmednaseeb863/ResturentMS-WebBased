<?php

namespace App\Http\Requests;

use App\Enums\StockAdjustmentType;
use App\Http\Requests\Concerns\ReadsStockLines;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Waste / damage: type, reason and the items written off (any unit they are counted in). */
class WasteRequest extends FormRequest
{
    use ReadsStockLines;

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(StockAdjustmentType::class)],
            'reason' => ['required', 'string', 'max:255'],
            ...$this->stockLineRules(false),
        ];
    }

    public function messages(): array
    {
        return [...$this->stockLineMessages(), 'reason.required' => 'Say what happened (expired, dropped, spoiled…).'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                $this->checkStockLines($validator);
            }
        }];
    }

    public function type(): StockAdjustmentType
    {
        return StockAdjustmentType::from($this->validated('type'));
    }
}
