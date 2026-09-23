<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the activity log (who / what / when / branch).
 *
 *   Activity::log('trashed', $branch, ['reason' => '…']);
 *   Activity::log('login', $admin);
 *
 * Properties must never hold numeric ids — use names/uuids (they are shown in the UI).
 */
class Activity
{
    /** Attributes never written to the log. */
    public const HIDDEN = [
        'id', 'uuid', 'password', 'pin', 'remember_token', 'created_at', 'updated_at',
        'deleted_at', 'deleted_by', 'delete_reason', 'trash_batch', 'last_login_at',
    ];

    public static function log(string $event, ?Model $subject = null, array $properties = [], ?string $description = null): ActivityLog
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return ActivityLog::create([
            'branch_id' => app(CurrentBranch::class)->id() ?? $subject?->getAttribute('branch_id'),
            'admin_id' => Auth::guard('admin')->id(),
            'event' => $event,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subject ? static::label($subject) : null,
            'description' => $description,
            'properties' => $properties ?: null,
            'ip_address' => $request?->ip(),
        ]);
    }

    public static function label(Model $subject): string
    {
        if (method_exists($subject, 'trashLabel')) {
            return $subject->trashLabel();
        }

        return (string) ($subject->getAttribute('name') ?? class_basename($subject));
    }

    /** Drop ids, foreign keys and secrets from an attribute array. */
    public static function clean(array $attributes, array $extraHidden = []): array
    {
        $hidden = [...self::HIDDEN, ...$extraHidden];

        return array_filter(
            $attributes,
            fn ($value, string $key) => ! in_array($key, $hidden, true) && ! str_ends_with($key, '_id'),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
