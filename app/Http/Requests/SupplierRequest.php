<?php

namespace App\Http\Requests;

use App\Rules\UniqueWithTrash;
use Illuminate\Foundation\Http\FormRequest;

class SupplierRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', new UniqueWithTrash('suppliers', 'name', $this->route('supplier')?->id, 'supplier')],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[\d\s-]+$/'],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'ntn' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'Use digits only (an optional + at the start).'];
    }

    public function supplierData(): array
    {
        return [
            ...collect($this->validated())->only(['name', 'contact_person', 'phone', 'email', 'address', 'ntn', 'notes'])->all(),
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
