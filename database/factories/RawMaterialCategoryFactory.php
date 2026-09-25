<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\RawMaterialCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RawMaterialCategory> */
class RawMaterialCategoryFactory extends Factory
{
    protected $model = RawMaterialCategory::class;

    public function definition(): array
    {
        return ['name' => fake()->unique()->bothify('Group ##??')];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(['branch_id' => $branch->id]);
    }
}
