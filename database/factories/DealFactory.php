<?php

namespace Database\Factories;

use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Deal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Deal> Slots are added by the test (see MenuTest / DealTest). */
class DealFactory extends Factory
{
    protected $model = Deal::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('Deal ##??'),
            'price' => 999,
            'available_for' => OrderType::values(),
            'is_active' => true,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(['branch_id' => $branch->id]);
    }
}
