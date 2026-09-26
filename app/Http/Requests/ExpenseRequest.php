<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * An expense: category, amount, description, paid from cash (my open shift's drawer) or a
 * bank account of the branch (with the business date it counts for), reference no., and a
 * receipt / bill photo or PDF.
 */
class ExpenseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'category' => ['required', 'uuid'],
            'description' => ['required', 'string', 'max:255'],
            'business_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            ...SupplierPaymentRequest::paymentRules('', true),
        ];
    }

    public function messages(): array
    {
        return ['category.required' => 'Pick the category.', 'description.required' => 'Say what the money was spent on.'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (! $this->category()) {
                $validator->errors()->add('category', 'Pick an active category.');
            }
            SupplierPaymentRequest::checkPayment($this, $validator, '');
        }];
    }

    public function category(): ?ExpenseCategory
    {
        return once(fn () => ExpenseCategory::query()->active()->where('uuid', $this->input('category'))->first());
    }

    public function method(): PaymentMethod
    {
        return SupplierPaymentRequest::methodOf($this);
    }
}
