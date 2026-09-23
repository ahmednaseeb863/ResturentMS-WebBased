<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * `sync()` for pivot tables whose rows are Trashable (admin_branch, permission_role…):
 * removed links are trashed, re-added links are restored — nothing is deleted.
 *
 *   TrashablePivot::sync(AdminBranch::class, 'admin_id', $admin->id, 'branch_id', $branchIds);
 *
 * @return array{attached: list<int>, detached: list<int>} related ids that changed
 */
class TrashablePivot
{
    public static function sync(string $pivotModel, string $ownerKey, int $ownerId, string $relatedKey, array $relatedIds): array
    {
        /** @var class-string<Model> $pivotModel */
        $rows = $pivotModel::query()->withTrashed()->where($ownerKey, $ownerId)->get()->keyBy($relatedKey);
        $wanted = array_values(array_unique(array_map('intval', $relatedIds)));

        $attached = [];
        foreach ($wanted as $relatedId) {
            $row = $rows->get($relatedId);

            if (! $row) {
                $pivotModel::query()->create([$ownerKey => $ownerId, $relatedKey => $relatedId]);
                $attached[] = $relatedId;
            } elseif ($row->isTrashed()) {
                $row->restoreFromTrash();
                $attached[] = $relatedId;
            }
        }

        $detached = [];
        foreach ($rows as $relatedId => $row) {
            if (! $row->isTrashed() && ! in_array($relatedId, $wanted, true)) {
                $row->trash();
                $detached[] = $relatedId;
            }
        }

        return ['attached' => $attached, 'detached' => $detached];
    }
}
