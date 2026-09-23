<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Unique check that also looks at trashed rows (CLAUDE.md §1). When the match is
 * in the trash, the message tells the user to restore it instead of adding a duplicate.
 *
 *   new UniqueWithTrash('branches', 'code', $branch?->id, 'branch')
 */
class UniqueWithTrash implements ValidationRule
{
    public function __construct(
        private string $table,
        private string $column,
        private ?int $ignoreId = null,
        private string $noun = 'record',
        private array $where = [],
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $match = DB::table($this->table)
            ->where($this->column, $value)
            ->where($this->where)
            ->when($this->ignoreId, fn ($q) => $q->where('id', '!=', $this->ignoreId))
            ->first(['id', 'deleted_at']);

        if (! $match) {
            return;
        }

        $fail($match->deleted_at !== null
            ? "A {$this->noun} in the trash already uses this :attribute — restore it from the Trash tab instead."
            : "Another {$this->noun} already uses this :attribute.");
    }
}
