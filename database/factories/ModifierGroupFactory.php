<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ModifierGroup> */
class ModifierGroupFactory extends Factory
{
    protected $model = ModifierGroup::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('Add-ons ##??'),
            'min_select' => 0,
            'max_select' => null,
            'is_active' => true,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(['branch_id' => $branch->id]);
    }
}
