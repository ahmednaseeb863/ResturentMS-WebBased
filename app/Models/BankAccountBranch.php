<?php

namespace App\Models;

use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** bank account ↔ branch availability. Removing a branch trashes the row (history kept). */
class BankAccountBranch extends Pivot
{
    use Trashable;

    protected $table = 'bank_account_branch';

    public $incrementing = true;

    protected bool $logTrashActivity = false;

    protected $fillable = ['bank_account_id', 'branch_id'];
}
