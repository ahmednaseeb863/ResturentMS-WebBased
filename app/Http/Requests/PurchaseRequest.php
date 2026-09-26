<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReadsStockLines;
use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A supplier invoice received into stock: supplier, invoice no. / date, lines (item,
 * quantity in any unit it is bought in, cost per that unit), invoice discount and tax,
 * and optionally money paid now (`pay` — cash from the shift drawer or a bank transfer).
 */
class PurchaseRequest extends FormRequest
{
    use ReadsStockLines;

    public function rules(): array
    {
        return [
            'supplier' => ['required', 'uuid'],
            'invoice_no' => ['nullable', 'string', 'max:60'],
            'invoice_date' => ['nullable', 'date', 'before_or_equal:today'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'tax' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'notes' => ['nullable', 'string', 'max:500'],
            ...$this->stockLineRules(true),
            ...SupplierPaymentRequest::paymentRules('pay.', false),
        ];
    }

    public function messages(): array
    {
        return [...$this->stockLineMessages(), 'supplier.required' => 'Pick the supplier.'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (! $this->supplier()) {
                $validator->errors()->add('supplier', 'Pick an active supplier.');
            }
            $this->checkStockLines($validator);
            if ($this->paidNow() > 0) {
                SupplierPaymentRequest::checkPayment($this, $validator, 'pay.');
            }
        }];
    }

    public function supplier(): ?Supplier
    {
        return once(fn () => Supplier::query()->active()->where('uuid', $this->input('supplier'))->first());
    }

    public function invoiceData(): array
    {
        return [
            'invoice_no' => $this->validated('invoice_no'),
            'invoice_date' => $this->validated('invoice_date'),
            'discount' => (float) ($this->validated('discount') ?? 0),
            'tax' => (float) ($this->validated('tax') ?? 0),
            'notes' => $this->validated('notes'),
        ];
    }

    public function paidNow(): float
    {
        return round((float) $this->input('pay.amount', 0), 2);
    }
}
