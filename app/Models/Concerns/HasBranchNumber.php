<?php

namespace App\Models\Concerns;

use App\Models\Branch;

/**
 * Documents numbered per branch (PUR-0012, W-0003, CNT-0002). `nextNumber()` locks the
 * branch row, so call it inside the transaction that creates the document.
 * The model sets `protected const CODE_PREFIX = 'PUR';`.
 */
trait HasBranchNumber
{
    public static function nextNumber(int $branchId): int
    {
        Branch::query()->lockForUpdate()->find($branchId);

        return (int) static::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)->max('number') + 1;
    }

    /** "PUR-0012" */
    public function code(): string
    {
        return static::CODE_PREFIX.'-'.str_pad((string) $this->number, 4, '0', STR_PAD_LEFT);
    }

    public function trashLabel(): string
    {
        return $this->code();
    }
}
