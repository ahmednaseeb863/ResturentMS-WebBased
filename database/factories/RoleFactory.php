<?php

namespace Database\Factories;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Support\TrashablePivot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Role> */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
            'description' => fake()->sentence(),
        ];
    }

    /** Grant the catalog permissions that cover these route names (run PermissionCatalog::sync() first). */
    public function withRoutes(string ...$routes): static
    {
        return $this->afterCreating(function (Role $role) use ($routes) {
            $ids = Permission::query()->get()
                ->filter(fn (Permission $p) => array_intersect($p->routes, $routes))
                ->pluck('id')->all();

            TrashablePivot::sync(RolePermission::class, 'role_id', $role->id, 'permission_id', $ids);
        });
    }
}
