<?php

namespace Database\Factories;

use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\ReadyItem;
use App\Models\Unit;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReadyItem> Counted in pcs; a category of the same branch is made when none is given. */
class ReadyItemFactory extends Factory
{
    protected $model = ReadyItem::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('Drink ##??'),
            'category_id' => fn (array $attributes) => Category::factory()->create([
                'branch_id' => $attributes['branch_id'] ?? app(CurrentBranch::class)->id(),
            ])->id,
            'price' => 150,
            'stock_unit_id' => fn () => Unit::query()->where('short_name', 'pcs')->value('id'),
            'available_for' => OrderType::values(),
            'is_active' => true,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(['branch_id' => $branch->id]);
    }
}
