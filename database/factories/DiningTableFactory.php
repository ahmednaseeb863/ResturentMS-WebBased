<?php

namespace Database\Factories;

use App\Enums\TableShape;
use App\Enums\TableStatus;
use App\Models\Area;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DiningTable> An area of the same branch is made when none is given; not placed on the plan. */
class DiningTableFactory extends Factory
{
    protected $model = DiningTable::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('T-###'),
            'area_id' => fn (array $attributes) => Area::factory()->create([
                'branch_id' => $attributes['branch_id'] ?? app(CurrentBranch::class)->id(),
            ])->id,
            'capacity' => 4,
            'shape' => TableShape::Square,
            'status' => TableStatus::Available,
            'is_active' => true,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(['branch_id' => $branch->id]);
    }

    public function at(int $x, int $y): static
    {
        return $this->state(['pos_x' => $x, 'pos_y' => $y]);
    }
}
