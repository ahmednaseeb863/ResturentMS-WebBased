<?php

namespace Database\Factories;

use App\Models\Admin;
use App\Models\AdminBranch;
use App\Models\Branch;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Admin> */
class AdminFactory extends Factory
{
    protected $model = Admin::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'pin' => '1234',
            'is_super_admin' => false,
            'is_active' => true,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(['is_super_admin' => true, 'role_id' => null]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function withRole(Role $role): static
    {
        return $this->state(['role_id' => $role->id]);
    }

    /** Give access to these branches (created if none given). */
    public function forBranches(Branch ...$branches): static
    {
        return $this->afterCreating(function (Admin $admin) use ($branches) {
            foreach ($branches ?: [Branch::factory()->create()] as $branch) {
                AdminBranch::query()->create(['admin_id' => $admin->id, 'branch_id' => $branch->id]);
            }
        });
    }
}
