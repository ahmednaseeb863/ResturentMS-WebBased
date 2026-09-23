<?php

namespace App\Models\Concerns;

use App\Support\Activity;
use Illuminate\Database\Eloquent\Model;

/**
 * Logs `created` and `updated` (old → new values) to the activity log.
 * Trash / restore are logged by Trashable. Ids, foreign keys and secrets are
 * never written; add more with `protected array $activityHidden = [...]`.
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(function (Model $model) {
            Activity::log('created', $model, ['attributes' => $model->activityAttributes($model->getAttributes())]);
        });

        static::updated(function (Model $model) {
            $new = $model->activityAttributes($model->getChanges());
            if ($new === []) {
                return; // only trash columns, timestamps, secrets or keys changed
            }

            $old = array_intersect_key($model->getPrevious(), $new);
            Activity::log('updated', $model, ['old' => $old, 'attributes' => $new]);
        });
    }

    public function activityAttributes(array $attributes): array
    {
        $extra = property_exists($this, 'activityHidden') ? $this->activityHidden : [];

        return Activity::clean($attributes, $extra);
    }
}
