<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One tick-box in the role editor; grants a list of route names. Defined in PermissionCatalog. */
class Permission extends Model
{
    use HasPublicUuid, Trashable;

    protected $fillable = ['permission_group_id', 'title', 'routes', 'sort_order'];

    protected bool $logTrashActivity = false;

    protected function casts(): array
    {
        return ['routes' => 'array'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(PermissionGroup::class, 'permission_group_id');
    }
}
