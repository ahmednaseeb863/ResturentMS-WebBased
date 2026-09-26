<?php

namespace App\Http\Requests;

use App\Enums\EmployeeStatus;
use App\Http\Requests\Concerns\ReadsCashCount;
use App\Models\CashCounter;
use App\Models\Employee;
use App\Models\ShiftType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

class OpenShiftRequest extends FormRequest
{
    use ReadsCashCount;

    public function rules(): array
    {
        return [
            'counter' => ['required', 'uuid'],
            'shift_type' => ['nullable', 'uuid'],
            'opening_cash' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            ...$this->countRules(),
            'notes' => ['nullable', 'string', 'max:500'],
            'staff' => ['nullable', 'array', 'max:50'],
            'staff.*' => ['uuid'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (! $this->counter()) {
                $validator->errors()->add('counter', 'Pick an active cash counter of this branch.');
            }
            if ($this->filled('shift_type') && ! $this->shiftType()) {
                $validator->errors()->add('shift_type', 'Pick an active shift type of this branch.');
            }
            if ($this->employees()->count() !== count(array_unique($this->input('staff', [])))) {
                $validator->errors()->add('staff', 'Pick active staff of this branch.');
            }
            $this->checkDenominations($validator);
        }];
    }

    public function counter(): ?CashCounter
    {
        return once(fn () => CashCounter::query()->active()->where('uuid', $this->input('counter'))->first());
    }

    public function shiftType(): ?ShiftType
    {
        return once(fn () => $this->filled('shift_type')
            ? ShiftType::query()->active()->where('uuid', $this->input('shift_type'))->first()
            : null);
    }

    /** @return Collection<int, Employee> */
    public function employees(): Collection
    {
        return once(fn () => Employee::query()
            ->whereIn('uuid', $this->input('staff', []))
            ->where('status', EmployeeStatus::Active)
            ->get());
    }

    /** The counted total when counted note by note, else the amount typed in. */
    public function openingCash(): float
    {
        return $this->hasCount() ? $this->countTotal() : round((float) $this->validated('opening_cash'), 2);
    }
}
