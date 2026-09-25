<?php

namespace Database\Factories;

use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MenuItem> A category of the same branch is made when none is given. */
class MenuItemFactory extends Factory
{
    protected $model = MenuItem::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('Dish ##??'),
            'category_id' => fn (array $attributes) => Category::factory()->create([
                'branch_id' => $attributes['branch_id'] ?? app(CurrentBranch::class)->id(),
            ])->id,
            'price' => 650,
            'available_for' => OrderType::values(),
            'is_active' => true,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(['branch_id' => $branch->id]);
    }
}
