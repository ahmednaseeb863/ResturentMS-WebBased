<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/** What an admin account may do: a set of permissions, each granting route names. */
class Role extends Model
{
    use HasFactory, HasPublicUuid, LogsActivity, Trashable;

    protected $fillable = ['name', 'description'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)
            ->using(RolePermission::class)
            ->wherePivotNull('deleted_at');
    }

    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class);
    }

    /** Flattened route names this role grants (cached; cleared by forgetRouteNames). */
    public function allowedRouteNames(): array
    {
        return Cache::rememberForever(static::cacheKey($this->id), fn () => $this->permissions()
            ->get(['permissions.routes'])
            ->pluck('routes')
            ->flatten()
            ->unique()
            ->values()
            ->all());
    }

    public function forgetRouteNames(): void
    {
        Cache::forget(static::cacheKey($this->id));
    }

    public static function forgetAllRouteNames(): void
    {
        static::query()->withTrashed()->pluck('id')->each(fn (int $id) => Cache::forget(static::cacheKey($id)));
    }

    protected static function cacheKey(int $id): string
    {
        return "role_routes:{$id}";
    }

    public function canBeTrashed(): true|string
    {
        $count = $this->admins()->count();

        return $count > 0
            ? "This role is assigned to {$count} admin account(s) — give them another role first."
            : true;
    }
}
