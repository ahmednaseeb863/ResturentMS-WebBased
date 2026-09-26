<?php

namespace App\Http\Resources;

use App\Models\Refund;
use Illuminate\Http\Request;

/** A refund. Load `bankAccount`, `refundedBy`, `approvedBy`, `shift`, `payment`; lists also `order`. */
class RefundResource extends Resource
{
    public function toArray(Request $request): array
    {
        /** @var Refund $refund */
        $refund = $this->resource;
        $bank = $refund->relationLoaded('bankAccount') ? $refund->bankAccount : null;

        return [
            'id' => $refund->uuid,
            'method' => ['value' => $refund->method->value, 'label' => $refund->method->label(), 'tone' => $refund->method->tone()],
            'bank' => $bank ? ['id' => $bank->uuid, 'name' => $bank->trashLabel(), 'bank' => $bank->bank_name] : null,
            'amount' => $refund->amount,
            'reason' => $refund->reason,
            'reference_no' => $refund->reference_no,
            'business_date' => $refund->business_date->toDateString(),
            'created_at' => static::iso($refund->created_at),
            'refunded_by' => $this->ref('refundedBy'),
            'approved_by' => $this->ref('approvedBy'),
            'shift' => $refund->relationLoaded('shift') && $refund->shift ? ['id' => $refund->shift->uuid, 'code' => $refund->shift->code()] : null,
            'payment' => $refund->relationLoaded('payment') && $refund->payment ? ['id' => $refund->payment->uuid] : null,
            'order' => $refund->relationLoaded('order') && $refund->order
                ? ['id' => $refund->order->uuid, 'code' => $refund->order->code(), 'label' => $refund->order->label()]
                : null,
        ];
    }
}
