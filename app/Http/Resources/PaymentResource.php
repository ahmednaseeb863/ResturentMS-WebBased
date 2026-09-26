<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;

/** A payment. Load `bankAccount`, `receivedBy`, `shift`, `split`, `refunds`; lists also `order`. */
class PaymentResource extends Resource
{
    public function toArray(Request $request): array
    {
        /** @var Payment $payment */
        $payment = $this->resource;
        $bank = $payment->relationLoaded('bankAccount') ? $payment->bankAccount : null;

        return [
            'id' => $payment->uuid,
            'method' => ['value' => $payment->method->value, 'label' => $payment->method->label(), 'tone' => $payment->method->tone()],
            'bank' => $bank ? ['id' => $bank->uuid, 'name' => $bank->trashLabel(), 'bank' => $bank->bank_name] : null,
            'amount' => $payment->amount,
            'tendered' => $payment->tendered,
            'change' => $payment->change_given,
            'reference_no' => $payment->reference_no,
            'proof_url' => $payment->proofUrl(),
            'refunded_total' => $payment->refunded_total,
            'refundable' => $payment->refundable(),
            'business_date' => $payment->business_date->toDateString(),
            'created_at' => static::iso($payment->created_at),
            'received_by' => $this->ref('receivedBy'),
            'shift' => $payment->relationLoaded('shift') && $payment->shift ? ['id' => $payment->shift->uuid, 'code' => $payment->shift->code()] : null,
            'split' => $payment->relationLoaded('split') && $payment->split ? ['id' => $payment->split->uuid, 'label' => $payment->split->label] : null,
            'order' => $payment->relationLoaded('order') && $payment->order
                ? ['id' => $payment->order->uuid, 'code' => $payment->order->code(), 'label' => $payment->order->label()]
                : null,
        ];
    }
}
