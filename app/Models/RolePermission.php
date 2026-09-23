<?php

namespace App\Models;

use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** role ↔ permission. Revoking trashes the row, so grant/revoke history is kept. */
class RolePermission extends Pivot
{
    use Trashable;

    protected $table = 'permission_role';

    public $incrementing = true;

    protected bool $logTrashActivity = false;

    protected $fillable = ['role_id', 'permission_id'];
}
