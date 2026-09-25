<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class CashCounterResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'receipt_printer' => $this->ref('receiptPrinter', ['name', 'is_active']),
            'is_active' => $this->is_active,
            $this->trashFields(),
        ];
    }
}
