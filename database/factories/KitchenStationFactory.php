<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\KitchenStation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KitchenStation> Pass `branch_id` (forBranch) or run inside CurrentBranch::actingAs(). */
class KitchenStationFactory extends Factory
{
    protected $model = KitchenStation::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('Station ##??'),
            'has_screen' => true,
            'is_active' => true,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(['branch_id' => $branch->id]);
    }
}
