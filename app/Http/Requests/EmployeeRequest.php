<?php

namespace App\Http\Requests;

use App\Enums\EmployeeStatus;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Role;
use App\Rules\UniqueWithTrash;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add / edit an employee and (optionally) their login account.
 *
 * The `login.*` fields are only used when the signed-in admin may manage logins:
 * `admins.store` to create one, `admins.update` + canManage() to edit one.
 * Otherwise they are ignored and the login is left as it is.
 */
class EmployeeRequest extends FormRequest
{
    public function rules(): array
    {
        $employee = $this->employee();

        $rules = [
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/', new UniqueWithTrash('employees', 'code', $employee?->id, 'employee')],
            'name' => ['required', 'string', 'max:120'],
            'designation' => ['required', 'uuid'],
            'phone' => ['nullable', 'string', 'max:30'],
            'cnic' => ['nullable', 'regex:/^\d{5}-\d{7}-\d$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'joining_date' => ['nullable', 'date'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'status' => ['required', Rule::enum(EmployeeStatus::class)],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_photo' => ['boolean'],
            'login.enabled' => ['boolean'],
        ];

        if (! $this->managesLogin()) {
            return $rules;
        }

        $admin = $this->existingLogin();

        return [
            ...$rules,
            'login.username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', new UniqueWithTrash('admins', 'username', $admin?->id, 'admin account')],
            'login.email' => ['nullable', 'email', 'max:150', new UniqueWithTrash('admins', 'email', $admin?->id, 'admin account')],
            'login.password' => [$admin ? 'nullable' : 'required', 'string', 'min:8', 'max:100', 'confirmed'],
            'login.pin' => ['nullable', 'digits_between:4,6'],
            'login.clear_pin' => ['boolean'],
            'login.role' => ['required', 'uuid', 'exists:roles,uuid,deleted_at,NULL'],
            'login.branches' => ['nullable', 'array'],
            'login.branches.*' => ['uuid', 'exists:branches,uuid,deleted_at,NULL'],
            'login.is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'cnic' => 'CNIC',
            'login.username' => 'username',
            'login.email' => 'email',
            'login.password' => 'password',
            'login.pin' => 'PIN',
            'login.role' => 'role',
            'login.branches' => 'branches',
        ];
    }

    public function messages(): array
    {
        return [
            'cnic.regex' => 'Use the format 12345-1234567-1.',
            'code.regex' => 'Use letters, numbers, dots, dashes or underscores only.',
            'login.username.regex' => 'Use letters, numbers, dots, dashes or underscores only.',
            'login.role.required' => 'Pick a role for this login.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $this->designation()) {
                $validator->errors()->add('designation', 'Pick an active designation.');
            }

            $actor = $this->actor();

            if ($this->boolean('login.enabled') && ! $this->existingLogin() && ! $actor->canRoute('admins.store')) {
                $validator->errors()->add('login.enabled', 'You do not have permission to create login accounts.');
            }

            if ($this->managesLogin() && ! $actor->is_super_admin) {
                $allowed = $actor->accessibleBranches()->pluck('uuid')->all();
                if (array_diff($this->input('login.branches', []), $allowed)) {
                    $validator->errors()->add('login.branches', 'You can only give access to your own branches.');
                }
            }

            if ($this->existingLogin()?->is($actor) && $this->input('status') !== EmployeeStatus::Active->value) {
                $validator->errors()->add('status', 'You cannot change the status of your own employee record.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    // ── Resolved values (for SaveEmployee) ────────────────────────────────

    public function employee(): ?Employee
    {
        return $this->route('employee');
    }

    /** The employee's current login, including one in the trash. */
    public function existingLogin(): ?Admin
    {
        return once(fn () => $this->employee()?->admin()->withTrashed()->first());
    }

    /** Should the login fields be validated and saved? */
    public function managesLogin(): bool
    {
        return once(function () {
            $actor = $this->actor();
            $admin = $this->existingLogin();

            if ($admin) {
                // a save without login fields leaves the login alone
                return $this->has('login.username') && ! $admin->isTrashed() && $actor->canRoute('admins.update') && $actor->canManage($admin);
            }

            return $this->boolean('login.enabled') && $actor->canRoute('admins.store');
        });
    }

    public function designation(): ?Designation
    {
        // active ones, or the one the employee already has
        return once(fn () => Designation::query()
            ->where('uuid', $this->input('designation'))
            ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $this->employee()?->designation_id))
            ->first());
    }

    /** Validated columns for the employees table. */
    public function employeeData(): array
    {
        return [
            'designation_id' => $this->designation()->id,
            'name' => $this->validated('name'),
            'phone' => $this->validated('phone'),
            'cnic' => $this->validated('cnic'),
            'address' => $this->validated('address'),
            'joining_date' => $this->validated('joining_date'),
            'salary' => $this->validated('salary'),
            'status' => $this->validated('status'),
        ];
    }

    /** Validated columns for the admins table (secrets only when given). */
    public function loginData(): array
    {
        $data = [
            'name' => $this->validated('name'),
            'username' => $this->validated('login.username'),
            'email' => $this->validated('login.email'),
            'role_id' => Role::query()->where('uuid', $this->validated('login.role'))->value('id'),
            'is_super_admin' => false,
            'is_active' => $this->boolean('login.is_active', true),
        ];

        if ($this->filled('login.password')) {
            $data['password'] = $this->validated('login.password');
        }

        if ($this->filled('login.pin')) {
            $data['pin'] = $this->validated('login.pin');
        } elseif ($this->boolean('login.clear_pin')) {
            $data['pin'] = null;
        }

        return $data;
    }

    /** @return list<int> extra branches picked in the form (home branch is added by SaveEmployee) */
    public function loginBranchIds(): array
    {
        return Branch::query()->whereIn('uuid', $this->validated('login.branches') ?? [])->pluck('id')->all();
    }

    public function actor(): Admin
    {
        return $this->user('admin');
    }
}
