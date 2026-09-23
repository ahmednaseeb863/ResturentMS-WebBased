<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Rules\UniqueWithTrash;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Add / edit a customer with their delivery addresses. Addresses are sent as a
 * list: existing ones carry their uuid, new ones none; the ones left out are trashed.
 */
class CustomerRequest extends FormRequest
{
    public function rules(): array
    {
        $ignore = $this->route('customer')?->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'min:7', 'max:20', 'regex:/^\+?\d+$/', new UniqueWithTrash('users', 'phone', $ignore, 'customer')],
            'email' => ['nullable', 'email', 'max:150', new UniqueWithTrash('users', 'email', $ignore, 'customer')],
            'birthday' => ['nullable', 'date', 'before:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'addresses' => ['nullable', 'array', 'max:10'],
            'addresses.*.id' => ['nullable', 'uuid'],
            'addresses.*.label' => ['nullable', 'string', 'max:40'],
            'addresses.*.address' => ['required', 'string', 'max:255'],
            'addresses.*.area' => ['nullable', 'string', 'max:100'],
            'addresses.*.landmark' => ['nullable', 'string', 'max:150'],
            'addresses.*.is_default' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'addresses.*.address' => 'address',
            'addresses.*.label' => 'label',
            'addresses.*.area' => 'area',
            'addresses.*.landmark' => 'landmark',
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'Use digits only (an optional + at the start).'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $customer = $this->route('customer');
            $known = $customer ? $customer->addresses()->pluck('uuid')->all() : [];

            foreach ($this->input('addresses', []) as $i => $address) {
                if (! empty($address['id']) && ! in_array($address['id'], $known, true)) {
                    $validator->errors()->add("addresses.{$i}.address", 'This address no longer exists — reload the page.');
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => Customer::normalizePhone($this->input('phone'))]);
    }

    public function customerData(): array
    {
        return collect($this->validated())->only(['name', 'phone', 'email', 'birthday', 'notes'])->all();
    }

    /** @return list<array<string, mixed>> exactly one marked default when any are given */
    public function addresses(): array
    {
        $addresses = array_values($this->validated('addresses') ?? []);
        $default = collect($addresses)->search(fn ($a) => ! empty($a['is_default']));
        $default = $default === false ? 0 : $default;

        return array_map(fn (array $a, int $i) => [
            'id' => $a['id'] ?? null,
            'label' => $a['label'] ?? null,
            'address' => $a['address'],
            'area' => $a['area'] ?? null,
            'landmark' => $a['landmark'] ?? null,
            'is_default' => $i === $default,
        ], $addresses, array_keys($addresses));
    }
}
