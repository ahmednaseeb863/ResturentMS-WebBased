<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class BankAccountResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'bank_name' => $this->bank_name,
            'account_title' => $this->account_title,
            'account_number' => $this->account_number,
            'iban' => $this->iban,
            'is_active' => $this->is_active,
            'show_on_receipt' => $this->show_on_receipt,
            'branches' => $this->refs('branches'),
            $this->trashFields(),
        ];
    }
}
