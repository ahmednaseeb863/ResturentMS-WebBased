<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\DeliveryZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeliveryZone> */
class DeliveryZoneFactory extends Factory
{
    protected $model = DeliveryZone::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('Zone ##??'),
            'fee' => 100,
            'is_active' => true,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(['branch_id' => $branch->id]);
    }
}
