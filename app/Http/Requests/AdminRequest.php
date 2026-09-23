<?php

namespace App\Http\Requests;

use App\Models\Admin;
use App\Models\Branch;
use App\Models\Role;
use App\Rules\UniqueWithTrash;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Create / edit an admin account. The form sends uuids (role, branches); use
 * `accountData()` / `branchIds()` for the internal ids.
 *
 * Only a super admin may create or edit super admins, and a normal admin can
 * only grant branches they can access themselves.
 */
class AdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('admin');

        return $target === null || $this->actor()->canManage($target);
    }

    public function rules(): array
    {
        $admin = $this->route('admin');
        $isSuper = $this->boolean('is_super_admin');

        return [
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', new UniqueWithTrash('admins', 'username', $admin?->id, 'admin account')],
            'email' => ['nullable', 'email', 'max:150', new UniqueWithTrash('admins', 'email', $admin?->id, 'admin account')],
            'password' => [$admin ? 'nullable' : 'required', 'string', 'min:8', 'max:100', 'confirmed'],
            'pin' => ['nullable', 'digits_between:4,6'],
            'clear_pin' => ['boolean'],
            'is_super_admin' => ['boolean'],
            'is_active' => ['boolean'],
            'role' => [$isSuper ? 'nullable' : 'required', 'uuid', 'exists:roles,uuid,deleted_at,NULL'],
            'branches' => [$isSuper ? 'nullable' : 'required', 'array'],
            'branches.*' => ['uuid', 'exists:branches,uuid,deleted_at,NULL'],
        ];
    }

    public function messages(): array
    {
        return [
            'role.required' => 'Pick a role for this account.',
            'branches.required' => 'Give this account access to at least one branch.',
            'username.regex' => 'Use letters, numbers, dots, dashes or underscores only.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $actor = $this->actor();
            $target = $this->route('admin');

            if ($this->boolean('is_super_admin') && ! $actor->is_super_admin) {
                $validator->errors()->add('is_super_admin', 'Only a super admin can create super admin accounts.');
            }

            if ($target?->is($actor) && ! $this->boolean('is_active', true)) {
                $validator->errors()->add('is_active', 'You cannot deactivate your own account.');
            }

            if ($target?->is($actor) && $target->is_super_admin && ! $this->boolean('is_super_admin')) {
                $validator->errors()->add('is_super_admin', 'You cannot remove your own super admin access.');
            }

            if (! $actor->is_super_admin) {
                $allowed = $actor->accessibleBranches()->pluck('uuid')->all();
                if (array_diff($this->input('branches', []), $allowed)) {
                    $validator->errors()->add('branches', 'You can only give access to your own branches.');
                }
            }
        }];
    }

    /** Validated columns for the admins table (ids resolved, secrets only when given). */
    public function accountData(): array
    {
        $isSuper = $this->boolean('is_super_admin');

        $data = [
            'name' => $this->validated('name'),
            'username' => $this->validated('username'),
            'email' => $this->validated('email'),
            'is_super_admin' => $isSuper,
            'is_active' => $this->boolean('is_active', true),
            'role_id' => $isSuper ? null : Role::query()->where('uuid', $this->validated('role'))->value('id'),
        ];

        if ($this->filled('password')) {
            $data['password'] = $this->validated('password');
        }

        if ($this->filled('pin')) {
            $data['pin'] = $this->validated('pin');
        } elseif ($this->boolean('clear_pin')) {
            $data['pin'] = null;
        }

        return $data;
    }

    /** @return list<int> */
    public function branchIds(): array
    {
        if ($this->boolean('is_super_admin')) {
            return []; // super admins see every branch
        }

        return Branch::query()->whereIn('uuid', $this->validated('branches', []))->pluck('id')->all();
    }

    private function actor(): Admin
    {
        return $this->user('admin');
    }
}
