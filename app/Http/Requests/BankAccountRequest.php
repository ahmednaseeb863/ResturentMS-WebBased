<?php

namespace App\Http\Requests;

use App\Models\Admin;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Rules\UniqueWithTrash;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Bank accounts are shared; non-super admins may only link / unlink their own branches. */
class BankAccountRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->account()?->id;

        return [
            'bank_name' => ['required', 'string', 'max:100'],
            'account_title' => ['required', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9 -]+$/', new UniqueWithTrash('bank_accounts', 'account_number', $id, 'bank account')],
            'iban' => ['nullable', 'string', 'regex:/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', new UniqueWithTrash('bank_accounts', 'iban', $id, 'bank account')],
            'is_active' => ['boolean'],
            'show_on_receipt' => ['boolean'],
            'branches' => ['required', 'array', 'min:1'],
            'branches.*' => ['uuid', 'exists:branches,uuid,deleted_at,NULL'],
        ];
    }

    public function attributes(): array
    {
        return ['iban' => 'IBAN'];
    }

    public function messages(): array
    {
        return [
            'account_number.regex' => 'Use letters, numbers, spaces or dashes only.',
            'iban.regex' => 'Enter a valid IBAN, e.g. PK36SCBL0000001123456702.',
            'branches.required' => 'Pick at least one branch where this account can be used.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $actor = $this->actor();

            if (! $actor->is_super_admin) {
                $allowed = $actor->accessibleBranches()->pluck('uuid')->all();
                if (array_diff($this->input('branches', []), $allowed)) {
                    $validator->errors()->add('branches', 'You can only pick your own branches.');
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'account_number' => trim((string) $this->input('account_number')),
            'iban' => $this->filled('iban') ? strtoupper(preg_replace('/\s+/', '', (string) $this->input('iban'))) : null,
        ]);
    }

    public function account(): ?BankAccount
    {
        return $this->route('bank_account');
    }

    public function accountData(): array
    {
        return [
            'bank_name' => $this->validated('bank_name'),
            'account_title' => $this->validated('account_title'),
            'account_number' => $this->validated('account_number'),
            'iban' => $this->validated('iban'),
            'is_active' => $this->boolean('is_active', true),
            'show_on_receipt' => $this->boolean('show_on_receipt'),
        ];
    }

    /** @return list<int> */
    public function branchIds(): array
    {
        return Branch::query()->whereIn('uuid', $this->validated('branches'))->pluck('id')->all();
    }

    public function actor(): Admin
    {
        return $this->user('admin');
    }
}
