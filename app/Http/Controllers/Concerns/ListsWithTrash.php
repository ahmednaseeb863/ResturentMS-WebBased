<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Active / Trash tabs on list screens. The Trash tab is only available to admins
 * who may restore (`<module>.restore`); others always get the active list.
 */
trait ListsWithTrash
{
    protected function listTab(Request $request, string $restoreRoute): string
    {
        return $request->query('tab') === 'trash' && $request->user('admin')->canRoute($restoreRoute)
            ? 'trash'
            : 'active';
    }

    protected function applyTab(Builder $query, string $tab): Builder
    {
        return $tab === 'trash'
            ? $query->onlyTrashed()->with('deletedBy')->latest('deleted_at')
            : $query;
    }

    /** @param class-string $model */
    protected function tabCounts(string $model): array
    {
        return [
            'active' => $model::query()->count(),
            'trash' => $model::query()->onlyTrashed()->count(),
        ];
    }

    protected function trashReason(Request $request, bool $required = false): ?string
    {
        $data = $request->validate([
            'reason' => [$required ? 'required' : 'nullable', 'string', 'max:500'],
        ]);

        return $data['reason'] ?? null;
    }
}
