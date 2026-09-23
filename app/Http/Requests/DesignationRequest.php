<?php

namespace App\Http\Requests;

use App\Enums\DesignationType;
use App\Models\Role;
use App\Rules\UniqueWithTrash;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DesignationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', new UniqueWithTrash('designations', 'name', $this->route('designation')?->id, 'designation')],
            'type' => ['required', Rule::enum(DesignationType::class)],
            'default_role' => ['nullable', 'uuid', 'exists:roles,uuid,deleted_at,NULL'],
            'is_active' => ['boolean'],
        ];
    }

    public function designationData(): array
    {
        return [
            'name' => $this->validated('name'),
            'type' => $this->validated('type'),
            'default_role_id' => $this->filled('default_role')
                ? Role::query()->where('uuid', $this->validated('default_role'))->value('id')
                : null,
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
