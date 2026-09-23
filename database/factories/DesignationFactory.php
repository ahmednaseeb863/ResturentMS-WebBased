<?php

namespace Database\Factories;

use App\Enums\DesignationType;
use App\Models\Designation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Designation> */
class DesignationFactory extends Factory
{
    protected $model = Designation::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
            'type' => DesignationType::Other,
            'is_active' => true,
        ];
    }

    public function type(DesignationType $type): static
    {
        return $this->state(['type' => $type]);
    }
}
