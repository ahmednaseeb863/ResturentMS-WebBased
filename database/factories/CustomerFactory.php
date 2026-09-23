<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->unique()->numerify('03#########'),
            'email' => fake()->unique()->safeEmail(),
        ];
    }

    public function withAddress(array $attributes = []): static
    {
        return $this->afterCreating(fn (Customer $customer) => CustomerAddress::query()->create([
            'user_id' => $customer->id,
            'label' => 'Home',
            'address' => fake()->streetAddress(),
            'area' => fake()->city(),
            'is_default' => true,
            ...$attributes,
        ]));
    }
}
