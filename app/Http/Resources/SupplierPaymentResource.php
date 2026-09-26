<?php

namespace App\Http\Resources;

use App\Models\SupplierPayment;
use Illuminate\Http\Request;

/** A payment to a supplier. Load `bankAccount`, `paidBy`, `shift` (lists also `purchase`, `supplier`). */
class SupplierPaymentResource extends Resource
{
    public function toArray(Request $request): array
    {
        /** @var SupplierPayment $payment */
        $payment = $this->resource;
        $bank = $payment->relationLoaded('bankAccount') ? $payment->bankAccount : null;

        return [
            'id' => $payment->uuid,
            'method' => ['value' => $payment->method->value, 'label' => $payment->method->label(), 'tone' => $payment->method->tone()],
            'amount' => $payment->amount,
            'bank' => $bank ? ['id' => $bank->uuid, 'name' => $bank->trashLabel()] : null,
            'reference_no' => $payment->reference_no,
            'notes' => $payment->notes,
            'business_date' => $payment->business_date->toDateString(),
            'created_at' => static::iso($payment->created_at),
            'paid_by' => $this->ref('paidBy'),
            'shift' => $payment->relationLoaded('shift') && $payment->shift ? ['id' => $payment->shift->uuid, 'code' => $payment->shift->code()] : null,
            'purchase' => $payment->relationLoaded('purchase') && $payment->purchase ? ['id' => $payment->purchase->uuid, 'code' => $payment->purchase->code()] : null,
        ];
    }
}
