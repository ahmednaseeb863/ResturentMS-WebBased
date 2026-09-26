<?php

namespace App\Http\Requests;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/** Adds staff on duty to an open shift. */
class ShiftStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->shift()->canBeHandledBy($this->user('admin'));
    }

    public function rules(): array
    {
        return [
            'staff' => ['required', 'array', 'min:1', 'max:50'],
            'staff.*' => ['uuid'],
        ];
    }

    public function messages(): array
    {
        return ['staff.required' => 'Pick who is on duty.'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (! $this->shift()->isOpen()) {
                $validator->errors()->add('staff', "Shift {$this->shift()->code()} is closed.");

                return;
            }
            if ($this->employees()->count() !== count(array_unique($this->input('staff')))) {
                $validator->errors()->add('staff', 'Pick active staff of this branch.');

                return;
            }

            $onDuty = $this->shift()->staff()->whereNull('checked_out_at')
                ->whereIn('employee_id', $this->employees()->pluck('id'))
                ->with('employee')
                ->first();
            if ($onDuty) {
                $validator->errors()->add('staff', "{$onDuty->employee->name} is already on duty.");
            }
        }];
    }

    public function shift(): Shift
    {
        return $this->route('shift');
    }

    /** @return Collection<int, Employee> */
    public function employees(): Collection
    {
        return once(fn () => Employee::query()
            ->whereIn('uuid', $this->input('staff', []))
            ->where('status', EmployeeStatus::Active)
            ->get());
    }
}
