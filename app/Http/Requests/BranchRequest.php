<?php

namespace App\Http\Requests;

use App\Enums\EmployeeStatus;
use App\Models\Branch;
use App\Models\Employee;
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
            'manager' => ['nullable', 'uuid'],
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

            if ($branch && $this->filled('manager') && ! $this->manager()) {
                $validator->errors()->add('manager', 'Pick an active employee of this branch.');
            }
        }];
    }

    /** Branch columns (manager uuid resolved to its id). */
    public function branchData(): array
    {
        return [
            ...collect($this->validated())->except('manager')->all(),
            'manager_id' => $this->manager()?->id,
        ];
    }

    /** The picked manager: an active employee whose home branch is this branch. */
    public function manager(): ?Employee
    {
        $branch = $this->route('branch');

        if (! $branch || ! $this->filled('manager')) {
            return null;
        }

        return once(fn () => Employee::query()->forBranch($branch)
            ->where('uuid', $this->input('manager'))
            ->where('status', EmployeeStatus::Active)
            ->first());
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
    }
}
