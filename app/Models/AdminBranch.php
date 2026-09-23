<?php

namespace App\Models;

use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** admin ↔ branch access. Removing access trashes the row (history kept). */
class AdminBranch extends Pivot
{
    use Trashable;

    protected $table = 'admin_branch';

    public $incrementing = true;

    protected bool $logTrashActivity = false;

    protected $fillable = ['admin_id', 'branch_id'];
}
