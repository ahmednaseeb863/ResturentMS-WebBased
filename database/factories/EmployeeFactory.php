<?php

namespace Database\Factories;

use App\Enums\EmployeeStatus;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Designation;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Employee> Pass `branch_id` or run inside CurrentBranch::actingAs(). */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'designation_id' => Designation::factory(),
            'code' => strtoupper(fake()->unique()->bothify('EMP-####')),
            'name' => fake()->name(),
            'phone' => fake()->numerify('03#########'),
            'status' => EmployeeStatus::Active,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(['branch_id' => $branch->id]);
    }

    /** With a login that can access the employee's home branch. */
    public function withLogin(?Admin $admin = null): static
    {
        return $this->afterCreating(function (Employee $employee) use ($admin) {
            $admin ??= Admin::factory()->create(['name' => $employee->name]);
            $employee->update(['admin_id' => $admin->id]);
            $admin->grantBranch($employee->branch);
        });
    }
}
