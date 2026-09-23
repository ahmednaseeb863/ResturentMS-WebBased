<?php

namespace App\Http\Requests;

use App\Models\Branch;
use App\Rules\UniqueWithTrash;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BranchRequest extends FormRequest
{
    public function rules(): array
    {
        $ignore = $this->route('branch')?->id;

        return [
            'code' => ['required', 'string', 'max:20', 'alpha_dash', new UniqueWithTrash('branches', 'code', $ignore, 'branch')],
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $branch = $this->route('branch');

            if ($branch && ! $this->boolean('is_active', true)
                && Branch::query()->active()->whereKeyNot($branch->id)->doesntExist()) {
                $validator->errors()->add('is_active', 'This is the only active branch — it cannot be deactivated.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
    }
}
