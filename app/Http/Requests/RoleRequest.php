<?php

namespace App\Http\Requests;

use App\Models\Permission;
use App\Rules\UniqueWithTrash;
use Illuminate\Foundation\Http\FormRequest;

class RoleRequest extends FormRequest
{
    /** A normal admin cannot change the role they hold themselves (no self-escalation). */
    public function authorize(): bool
    {
        $role = $this->route('role');
        $actor = $this->user('admin');

        return $role === null || $actor->is_super_admin || $actor->role_id !== $role->id;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', new UniqueWithTrash('roles', 'name', $this->route('role')?->id, 'role')],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['uuid', 'exists:permissions,uuid,deleted_at,NULL'],
        ];
    }

    /** @return list<int> */
    public function permissionIds(): array
    {
        return Permission::query()->whereIn('uuid', $this->validated('permissions', []))->pluck('id')->all();
    }
}
