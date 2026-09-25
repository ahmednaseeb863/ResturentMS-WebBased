<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CashCounter;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashCounter> Pass `branch_id` (forBranch) or run inside CurrentBranch::actingAs(). */
class CashCounterFactory extends Factory
{
    protected $model = CashCounter::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('Counter ##'),
            'is_active' => true,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(['branch_id' => $branch->id]);
    }
}
