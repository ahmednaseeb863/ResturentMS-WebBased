<?php

namespace App\Http\Resources;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MergeValue;
use Illuminate\Http\Resources\MissingValue;

/**
 * Base for every resource sent to React (CLAUDE.md §2): `id` is always the uuid,
 * relations are `{ id: uuid, … }`, never foreign keys.
 *
 *   'id' => $this->uuid,
 *   'category' => $this->ref('category'),          // { id, name } or null
 *   $this->trashFields(),                          // deleted_at / deleted_by / delete_reason (when trashed)
 */
abstract class Resource extends JsonResource
{
    /** `{ id: uuid, ...fields }` for a loaded relation, null when empty or not loaded. */
    protected function ref(string $relation, array $fields = ['name']): ?array
    {
        if (! $this->resource->relationLoaded($relation)) {
            return null;
        }

        $related = $this->resource->getRelation($relation);

        return $related instanceof Model ? static::refOf($related, $fields) : null;
    }

    /** `[{ id: uuid, ...fields }]` for a loaded to-many relation. */
    protected function refs(string $relation, array $fields = ['name']): array
    {
        if (! $this->resource->relationLoaded($relation)) {
            return [];
        }

        return $this->resource->getRelation($relation)->map(fn (Model $m) => static::refOf($m, $fields))->values()->all();
    }

    public static function refOf(Model $model, array $fields = ['name']): array
    {
        return ['id' => $model->getAttribute('uuid'), ...$model->only($fields)];
    }

    /** Trash info for Trash tabs; eager load `deletedBy` to get the name. */
    protected function trashFields(): MergeValue|MissingValue
    {
        $model = $this->resource;

        return $this->mergeWhen(method_exists($model, 'isTrashed') && $model->isTrashed(), fn () => [
            'deleted_at' => static::iso($model->deleted_at),
            'deleted_by' => $model->relationLoaded('deletedBy') ? $model->getRelation('deletedBy')?->name : null,
            'delete_reason' => $model->delete_reason,
        ]);
    }

    public static function iso(?DateTimeInterface $date): ?string
    {
        return $date?->format(DATE_ATOM);
    }
}
