<?php

namespace App\Support;

use App\Models\Branch;
use Illuminate\Support\Collection;

/**
 * The branch the signed-in admin is working in (singleton, set by SetCurrentBranch).
 *
 * Once `enforce()` is called (every authenticated web request), BelongsToBranch
 * models are filtered to this branch — and to nothing at all when no branch is set,
 * so a query can never leak another branch's data.
 */
class CurrentBranch
{
    public const SESSION_KEY = 'current_branch_id';

    protected ?Branch $branch = null;

    protected bool $enforced = false;

    /** @var Collection<int, Branch> */
    protected Collection $available;

    public function __construct()
    {
        $this->available = collect();
    }

    public function set(?Branch $branch, ?Collection $available = null): void
    {
        $this->branch = $branch;
        $this->available = $available ?? collect($branch ? [$branch] : []);
    }

    public function get(): ?Branch
    {
        return $this->branch;
    }

    public function id(): ?int
    {
        return $this->branch?->id;
    }

    /** Branches the admin may switch to. */
    public function available(): Collection
    {
        return $this->available;
    }

    public function enforce(bool $enforce = true): void
    {
        $this->enforced = $enforce;
    }

    public function isEnforced(): bool
    {
        return $this->enforced;
    }

    /** Run a callback as if another branch were current (jobs, seeders, tests). */
    public function actingAs(Branch $branch, callable $callback): mixed
    {
        [$previous, $previousAvailable] = [$this->branch, $this->available];
        $this->set($branch);

        try {
            return $callback();
        } finally {
            $this->set($previous, $previousAvailable);
        }
    }
}
