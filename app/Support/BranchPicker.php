<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * "Which branches?" for the dashboard and reports (PLAN §4.19): `?branch=<uuid>` or
 * `?branch=all` (every branch the admin may work in). Default: the current branch. Only
 * branches the admin has access to are ever used.
 */
class BranchPicker
{
    /** @return Collection<int, Branch> */
    public static function branches(Request $request): Collection
    {
        $admin = $request->user('admin');
        $available = $admin->accessibleBranches();
        $wanted = (string) $request->query('branch');

        if ($wanted === 'all' && $available->count() > 1) {
            return $available->values();
        }

        $branch = $wanted !== '' ? $available->firstWhere('uuid', $wanted) : null;
        $branch ??= $available->firstWhere('id', app(CurrentBranch::class)->id()) ?? $available->first();

        return collect($branch ? [$branch] : []);
    }

    /** The value of the picker: a branch uuid or "all". */
    public static function selected(Collection $branches): string
    {
        return $branches->count() > 1 ? 'all' : (string) $branches->first()?->uuid;
    }

    /** @return list<array{value: string, label: string}> — empty when there is nothing to pick */
    public static function options(Admin $admin): array
    {
        $available = $admin->accessibleBranches();
        if ($available->count() < 2) {
            return [];
        }

        return [
            ['value' => 'all', 'label' => 'All branches'],
            ...$available->map(fn (Branch $b) => ['value' => $b->uuid, 'label' => $b->name])->values()->all(),
        ];
    }
}
