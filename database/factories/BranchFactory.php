<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('BR-###')),
            'name' => fake()->city().' Branch',
            'address' => fake()->address(),
            'phone' => fake()->numerify('03#########'),
            'is_active' => true,
        ];
    }
}
