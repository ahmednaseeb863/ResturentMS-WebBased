<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ShiftType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShiftType> Pass `branch_id` (forBranch) or run inside CurrentBranch::actingAs(). */
class ShiftTypeFactory extends Factory
{
    protected $model = ShiftType::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('Shift ##??'),
            'start_time' => '11:00',
            'end_time' => '19:00',
            'is_active' => true,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(['branch_id' => $branch->id]);
    }

    public function overnight(): static
    {
        return $this->state(['start_time' => '19:00', 'end_time' => '04:00']);
    }
}
