<?php

namespace Database\Factories;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Models\Branch;
use App\Models\Discount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Discount> 10% off the order. */
class DiscountFactory extends Factory
{
    protected $model = Discount::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('Discount ##??'),
            'type' => DiscountType::Percent,
            'value' => 10,
            'applies_to' => DiscountScope::Order,
            'is_active' => true,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(['branch_id' => $branch->id]);
    }
}
