<?php

namespace App\Http\Resources;

use App\Models\Expense;
use Illuminate\Http\Request;

/** An expense; load `category`, `bankAccount`, `shift`, `createdBy`, `voidedBy`. */
class ExpenseResource extends Resource
{
    public function toArray(Request $request): array
    {
        /** @var Expense $e */
        $e = $this->resource;

        return [
            'id' => $e->uuid,
            'code' => $e->code(),
            'category' => $this->ref('category'),
            'business_date' => $e->business_date->toDateString(),
            'amount' => $e->amount,
            'description' => $e->description,
            'reference_no' => $e->reference_no,
            'method' => ['value' => $e->paid_from->value, 'label' => $e->paid_from->label(), 'tone' => $e->paid_from->tone()],
            'bank' => $e->relationLoaded('bankAccount') && $e->bankAccount ? ['id' => $e->bankAccount->uuid, 'name' => $e->bankAccount->trashLabel()] : null,
            'shift' => $e->relationLoaded('shift') && $e->shift ? ['id' => $e->shift->uuid, 'code' => $e->shift->code()] : null,
            'attachment_url' => $e->attachmentUrl(),
            'attachment_is_pdf' => $e->attachment ? str_ends_with(strtolower($e->attachment), '.pdf') : false,
            'created_by' => $this->ref('createdBy'),
            'created_at' => static::iso($e->created_at),
            'voided' => $e->isVoided(),
            'voided_at' => static::iso($e->voided_at),
            'voided_by' => $this->ref('voidedBy'),
            'void_reason' => $e->void_reason,
        ];
    }
}
