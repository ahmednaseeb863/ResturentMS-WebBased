<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminRequest;
use App\Http\Resources\AdminResource;
use App\Http\Resources\BranchOptionResource;
use App\Models\Admin;
use App\Models\AdminBranch;
use App\Models\Branch;
use App\Models\Role;
use App\Support\Activity;
use App\Support\TrashablePivot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'admins.restore');
        $search = trim((string) $request->query('search'));
        $role = (string) $request->query('role', '');
        $actor = $request->user('admin');

        $admins = $this->applyTab(Admin::query(), $tab)
            ->with(['role', 'branches'])
            // a normal admin only sees accounts that share one of their branches
            ->when(! $actor->is_super_admin, fn ($q) => $q->where('is_super_admin', false)
                ->whereHas('branches', fn ($b) => $b->whereIn('branches.id', $actor->accessibleBranches()->pluck('id'))))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($role === 'super', fn ($q) => $q->where('is_super_admin', true))
            ->when($role !== '' && $role !== 'super', fn ($q) => $q->whereRelation('role', 'uuid', $role))
            ->orderByDesc('is_super_admin')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('admins/Index', [
            'admins' => AdminResource::collection($admins),
            'filters' => ['tab' => $tab, 'search' => $search, 'role' => $role],
            'counts' => $this->tabCounts(Admin::class),
            'roles' => Role::query()->orderBy('name')->get()->map(fn (Role $r) => ['id' => $r->uuid, 'name' => $r->name]),
            'branches' => BranchOptionResource::collection(
                $actor->is_super_admin ? Branch::query()->orderBy('name')->get() : $actor->accessibleBranches(),
            ),
        ]);
    }

    public function store(AdminRequest $request): RedirectResponse
    {
        $admin = DB::transaction(function () use ($request) {
            $admin = Admin::create($request->accountData());
            $this->syncBranches($admin, $request->branchIds());

            return $admin;
        });

        return back()->with('success', "Admin account “{$admin->name}” added.");
    }

    public function update(AdminRequest $request, Admin $admin): RedirectResponse
    {
        $actor = $request->user('admin');
        $branchIds = $request->branchIds();

        // A normal admin only edits access to their own branches; keep the rest untouched.
        if (! $actor->is_super_admin) {
            $outside = $admin->branches()->pluck('branches.id')->diff($actor->accessibleBranches()->pluck('id'));
            $branchIds = [...$branchIds, ...$outside->all()];
        }

        DB::transaction(function () use ($request, $admin, $branchIds) {
            $oldRole = $admin->role?->name;
            $admin->update($request->accountData());

            if ($admin->wasChanged('role_id')) {
                Activity::log('role_changed', $admin, ['old' => ['role' => $oldRole], 'attributes' => ['role' => $admin->fresh('role')->role?->name]]);
            }

            $this->syncBranches($admin, $branchIds);
        });

        return back()->with('success', "Admin account “{$admin->name}” saved.");
    }

    public function destroy(Request $request, Admin $admin): RedirectResponse
    {
        abort_unless($request->user('admin')->canManage($admin), 403);

        $admin->trash($this->trashReason($request));

        return back()->with('success', "Admin account “{$admin->name}” moved to trash.");
    }

    public function restore(Request $request, Admin $admin): RedirectResponse
    {
        abort_unless($request->user('admin')->canManage($admin), 403);

        $admin->restoreFromTrash();

        return back()->with('success', "Admin account “{$admin->name}” restored.");
    }

    private function syncBranches(Admin $admin, array $branchIds): void
    {
        $changes = TrashablePivot::sync(AdminBranch::class, 'admin_id', $admin->id, 'branch_id', $branchIds);

        if ($changes['attached'] || $changes['detached']) {
            $names = fn (array $ids) => Branch::withTrashed()->whereIn('id', $ids)->pluck('name')->all();

            Activity::log('branch_access', $admin, [
                'granted' => $names($changes['attached']),
                'removed' => $names($changes['detached']),
            ]);
        }
    }
}
