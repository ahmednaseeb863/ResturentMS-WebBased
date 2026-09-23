<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Public key for everything outside the server (CLAUDE.md §2).
 *
 * - `uuid` (v7, time-ordered) is filled on create and never changes
 * - route model binding uses `uuid`
 * - `id` and every `*_id` attribute are hidden from toArray()/JSON
 *
 * Table needs `$table->publicUuid()`.
 */
trait HasPublicUuid
{
    public static function bootHasPublicUuid(): void
    {
        static::creating(function (Model $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid7();
            }
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('uuid')) {
                $model->uuid = $model->getOriginal('uuid');
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getHidden(): array
    {
        $keys = array_filter(
            array_keys($this->getAttributes()),
            fn (string $key) => $key === 'id' || str_ends_with($key, '_id'),
        );

        return array_values(array_unique([...$this->hidden, ...$keys]));
    }

    public static function findByUuid(?string $uuid): ?static
    {
        return $uuid ? static::query()->where('uuid', $uuid)->first() : null;
    }

    public static function findByUuidOrFail(string $uuid): static
    {
        return static::query()->where('uuid', $uuid)->firstOrFail();
    }
}
