<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Http\Resources\PermissionGroupResource;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\Role;
use App\Models\RolePermission;
use App\Support\Activity;
use App\Support\TrashablePivot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'roles.restore');
        $search = trim((string) $request->query('search'));

        $roles = $this->applyTab(Role::query(), $tab)
            ->with('permissions:permissions.id,permissions.uuid')
            ->withCount('admins')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(24)
            ->withQueryString();

        return Inertia::render('roles/Index', [
            'roles' => RoleResource::collection($roles),
            'filters' => ['tab' => $tab, 'search' => $search],
            'counts' => $this->tabCounts(Role::class),
            'groups' => PermissionGroupResource::collection(
                PermissionGroup::query()->orderBy('sort_order')->with('permissions')->get(),
            ),
        ]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $role = DB::transaction(function () use ($request) {
            $role = Role::create($request->safe()->only(['name', 'description']));
            $this->syncPermissions($role, $request->permissionIds());

            return $role;
        });

        return back()->with('success', "Role “{$role->name}” added.");
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        DB::transaction(function () use ($request, $role) {
            $role->update($request->safe()->only(['name', 'description']));
            $this->syncPermissions($role, $request->permissionIds());
        });

        return back()->with('success', "Role “{$role->name}” saved.");
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $role->trash($this->trashReason($request));

        return back()->with('success', "Role “{$role->name}” moved to trash.");
    }

    public function restore(Role $role): RedirectResponse
    {
        $role->restoreFromTrash();

        return back()->with('success', "Role “{$role->name}” restored.");
    }

    private function syncPermissions(Role $role, array $permissionIds): void
    {
        $changes = TrashablePivot::sync(RolePermission::class, 'role_id', $role->id, 'permission_id', $permissionIds);
        $role->forgetRouteNames();

        if ($changes['attached'] || $changes['detached']) {
            $titles = fn (array $ids) => Permission::withTrashed()->whereIn('id', $ids)->pluck('title')->all();

            Activity::log('permissions', $role, [
                'granted' => $titles($changes['attached']),
                'revoked' => $titles($changes['detached']),
            ]);
        }
    }
}
