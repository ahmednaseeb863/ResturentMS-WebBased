<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\RawMaterial;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RawMaterial> Kept in kg by default (`unit('g')` to change).
 * Stock is 0 — add stock through StockLedger, never by setting current_stock.
 */
class RawMaterialFactory extends Factory
{
    protected $model = RawMaterial::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('Material ##??'),
            'stock_unit_id' => fn () => Unit::query()->where('short_name', 'kg')->value('id'),
            'is_active' => true,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(['branch_id' => $branch->id]);
    }

    public function unit(string $shortName): static
    {
        return $this->state(['stock_unit_id' => fn () => Unit::query()->where('short_name', $shortName)->value('id')]);
    }
}
