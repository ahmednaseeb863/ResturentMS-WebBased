<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** Staff on duty during a shift. Load `employee.designation`. */
class ShiftEmployeeResource extends Resource
{
    public function toArray(Request $request): array
    {
        $employee = $this->employee;

        return [
            'id' => $this->uuid,
            'employee' => $employee ? [
                'id' => $employee->uuid,
                'name' => $employee->name,
                'designation' => $employee->designation?->name,
            ] : null,
            'checked_in_at' => static::iso($this->checked_in_at),
            'checked_out_at' => static::iso($this->checked_out_at),
            'on_duty' => $this->isOnDuty(),
        ];
    }
}
