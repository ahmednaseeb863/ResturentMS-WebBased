<?php

namespace App\Http\Requests;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Models\Discount;
use App\Rules\UniqueWithTrash;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DiscountRequest extends FormRequest
{
    public function rules(): array
    {
        $percent = $this->input('type') === DiscountType::Percent->value;

        return [
            'name' => ['required', 'string', 'max:80', new UniqueWithTrash('discounts', 'name', $this->discount()?->id, 'discount', ['branch_id' => app(CurrentBranch::class)->id()])],
            'type' => ['required', Rule::enum(DiscountType::class)],
            'value' => ['required', 'numeric', 'gt:0', $percent ? 'max:100' : 'max:9999999'],
            'applies_to' => ['required', Rule::enum(DiscountScope::class)],
            // a cap only makes sense for a percent discount
            'max_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999', Rule::prohibitedIf(! $percent)],
            'min_order_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'requires_approval' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'max_amount' => 'maximum discount',
            'min_order_amount' => 'minimum amount',
            'starts_on' => 'start date',
            'ends_on' => 'end date',
        ];
    }

    public function messages(): array
    {
        return [
            'value.max' => $this->input('type') === DiscountType::Percent->value ? 'A percent discount cannot be more than 100%.' : 'The amount is too large.',
            'value.gt' => 'Enter a discount above zero.',
            'max_amount.prohibited' => 'Only a percent discount can have a maximum.',
            'ends_on.after_or_equal' => 'The end date cannot be before the start date.',
        ];
    }

    public function discount(): ?Discount
    {
        return $this->route('discount');
    }

    public function discountData(): array
    {
        return [
            'name' => $this->validated('name'),
            'type' => $this->validated('type'),
            'value' => $this->validated('value'),
            'applies_to' => $this->validated('applies_to'),
            'max_amount' => $this->validated('max_amount'),
            'min_order_amount' => $this->validated('min_order_amount'),
            'starts_on' => $this->validated('starts_on'),
            'ends_on' => $this->validated('ends_on'),
            'requires_approval' => $this->boolean('requires_approval'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
