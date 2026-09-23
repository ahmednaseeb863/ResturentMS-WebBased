<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use App\Support\Activity;
use App\Support\TrashablePivot;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/** A login account (`admin` guard): the super admin or an employee's login. */
class Admin extends Authenticatable
{
    use HasFactory, HasPublicUuid, LogsActivity, Trashable;

    protected $fillable = ['role_id', 'name', 'username', 'email', 'password', 'pin', 'is_super_admin', 'is_active'];

    protected $hidden = ['password', 'pin', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'pin' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->using(AdminBranch::class)->wherePivotNull('deleted_at');
    }

    /** The staff record this login belongs to (any branch). Null for the super admin. */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class)->allBranches();
    }

    /**
     * Set the branches this admin may work in (removed links are trashed, not
     * deleted) and log what changed.
     *
     * @param  list<int>  $branchIds
     */
    public function syncBranches(array $branchIds): void
    {
        $changes = TrashablePivot::sync(AdminBranch::class, 'admin_id', $this->id, 'branch_id', $branchIds);
        $this->logBranchAccess($changes['attached'], $changes['detached']);
    }

    /** Add one branch, keeping the others (e.g. an allocated manager). */
    public function grantBranch(Branch $branch): void
    {
        if ($this->is_super_admin || $this->branches()->whereKey($branch->id)->exists()) {
            return;
        }

        $current = AdminBranch::query()->where('admin_id', $this->id)->pluck('branch_id')->all();
        $this->syncBranches([...$current, $branch->id]);
    }

    private function logBranchAccess(array $attached, array $detached): void
    {
        if (! $attached && ! $detached) {
            return;
        }

        $names = fn (array $ids) => Branch::withTrashed()->whereIn('id', $ids)->pluck('name')->all();

        Activity::log('branch_access', $this, ['granted' => $names($attached), 'removed' => $names($detached)]);
    }

    /** Active branches this admin may work in. Super admins: all. */
    public function accessibleBranches(): Collection
    {
        $query = $this->is_super_admin ? Branch::query() : $this->branches();

        return $query->where('branches.is_active', true)->orderBy('branches.name')->get();
    }

    public function canAccessBranch(Branch $branch): bool
    {
        return $this->accessibleBranches()->contains('id', $branch->id);
    }

    /** May this admin hit the named route? (Route-name based permissions.) */
    public function canRoute(string $routeName): bool
    {
        if ($this->is_super_admin || in_array($routeName, config('permissions.whitelist', []), true)) {
            return true;
        }

        return in_array($routeName, $this->allowedRouteNames(), true);
    }

    public function allowedRouteNames(): array
    {
        return $this->loadMissing('role')->role?->allowedRouteNames() ?? [];
    }

    /** Super admins manage everyone; others only non-super accounts sharing one of their branches. */
    public function canManage(Admin $other): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        if ($other->is_super_admin) {
            return false;
        }

        $mine = $this->accessibleBranches()->pluck('id');

        return $other->branches()->whereIn('branches.id', $mine)->exists()
            || $other->branches()->doesntExist(); // brand-new / orphaned account
    }

    public function checkPin(string $pin): bool
    {
        return $this->pin !== null && Hash::check($pin, $this->pin);
    }

    public function canBeTrashed(): true|string
    {
        if ($this->is(auth('admin')->user())) {
            return 'You cannot move your own account to the trash.';
        }

        if ($this->is_super_admin && static::query()->where('is_super_admin', true)->where('is_active', true)
            ->whereKeyNot($this->getKey())->doesntExist()) {
            return 'This is the last active super admin account.';
        }

        return true;
    }

    public function initials(): string
    {
        return collect(explode(' ', trim($this->name)))->filter()->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    }
}
