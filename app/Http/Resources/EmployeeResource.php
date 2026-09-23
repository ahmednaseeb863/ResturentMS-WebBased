<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class EmployeeResource extends Resource
{
    public function toArray(Request $request): array
    {
        $admin = $this->resource->relationLoaded('admin') ? $this->admin : null;

        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'initials' => collect(explode(' ', trim($this->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode(''),
            'phone' => $this->phone,
            'cnic' => $this->cnic,
            'address' => $this->address,
            'photo_url' => $this->photoUrl(),
            'joining_date' => $this->joining_date?->toDateString(),
            // salary is info only — shown to admins who may edit employees
            'salary' => $this->when($request->user('admin')?->canRoute('employees.update'), $this->salary),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'designation' => $this->ref('designation', ['name', 'type']),
            'login' => $admin ? [
                'id' => $admin->uuid,
                'username' => $admin->username,
                'email' => $admin->email,
                'is_active' => $admin->is_active,
                'has_pin' => $admin->pin !== null,
                'in_trash' => $admin->isTrashed(),
                'last_login_at' => static::iso($admin->last_login_at),
                'role' => $admin->relationLoaded('role') && $admin->role ? static::refOf($admin->role) : null,
                'branches' => $admin->relationLoaded('branches')
                    ? $admin->branches->map(fn ($b) => static::refOf($b, ['name', 'code']))->values()->all()
                    : [],
            ] : null,
            $this->trashFields(),
        ];
    }
}
