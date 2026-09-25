<?php

namespace App\Actions;

use App\Http\Requests\SettingsRequest;
use App\Models\Branch;
use App\Models\Setting;
use App\Support\Activity;
use App\Support\Settings\SettingsRegistry;
use App\Support\Settings\SettingsResolver;
use Illuminate\Support\Facades\DB;

/**
 * Saves one settings group (PLAN §4.5).
 *
 * - Global: a row is written when the value differs from what is stored / the default.
 * - Branch: overridden keys get a row (a trashed one is restored); keys switched back
 *   to "use global" have their row trashed, so the history stays.
 * - Images are stored on the public disk under settings/; old files are kept.
 * - One `settings` activity entry per save lists what changed; the cache is cleared.
 */
class SaveSettings
{
    private const USE_GLOBAL = 'Use global';

    public function __construct(private SettingsResolver $resolver) {}

    /** @return int number of fields changed */
    public function handle(SettingsRequest $request): int
    {
        $group = $request->group();
        $branch = $request->branch();
        $fields = $request->fields();
        $adminId = $request->user('admin')->id;

        $changes = DB::transaction(function () use ($request, $group, $branch, $fields, $adminId) {
            $rows = Setting::withTrashed()
                ->where('group', $group)
                ->where('branch_id', $branch?->id)
                ->get()
                ->keyBy('key');

            $changes = ['old' => [], 'attributes' => []];

            foreach ($fields as $key => $field) {
                $row = $rows->get($key);
                $stored = $row && ! $row->isTrashed();
                $before = $stored ? $row->value : $this->inherited($group, $key, $branch);

                if (! $request->isStored($key)) {
                    // branch field back to "use global"
                    if ($stored) {
                        $row->trash('Back to the global value');
                        $this->record($changes, $field, $before, self::USE_GLOBAL);
                    }

                    continue;
                }

                $value = $field['type'] === 'image'
                    ? $this->imageValue($request, $key, $before)
                    : $request->values()[$key];

                if ($stored && $this->same($row->value, $value)) {
                    continue;
                }

                if (! $stored && $branch === null && $this->same($value, $before)) {
                    continue; // global value equal to the default: nothing to store
                }

                $row ??= new Setting(['branch_id' => $branch?->id, 'group' => $group, 'key' => $key]);
                if ($row->exists && $row->isTrashed()) {
                    $row->restoreFromTrash();
                }
                $row->fill(['value' => $value, 'updated_by' => $adminId])->save();

                $this->record($changes, $field, $stored || $branch === null ? $before : self::USE_GLOBAL, $value);
            }

            return $changes;
        });

        if ($changes['attributes']) {
            Activity::log('settings', $branch, [
                'group' => SettingsRegistry::groups()[$group]['label'],
                'scope' => $branch ? $branch->name : 'Global',
                ...$changes,
            ], $branch ? null : 'Global settings');
        }

        $this->resolver->forget($branch?->id);

        return count($changes['attributes']);
    }

    /** What the field shows before this save when it has no row of its own. */
    private function inherited(string $group, string $key, ?Branch $branch): mixed
    {
        return $branch
            ? $this->resolver->globalValue("{$group}.{$key}")
            : SettingsRegistry::defaults()["{$group}.{$key}"];
    }

    private function imageValue(SettingsRequest $request, string $key, mixed $current): ?string
    {
        if ($file = $request->file("files.{$key}")) {
            return $file->store('settings', 'public');
        }

        return $request->boolean("remove.{$key}") ? null : $current;
    }

    /** 16 and 16.0 are the same value (JSON brings numbers back as int or float). */
    private function same(mixed $a, mixed $b): bool
    {
        return is_numeric($a) && is_numeric($b) && ! is_string($a) && ! is_string($b)
            ? (float) $a === (float) $b
            : $a === $b;
    }

    private function record(array &$changes, array $field, mixed $old, mixed $new): void
    {
        $label = $field['label'];
        $changes['old'][$label] = $this->display($field, $old);
        $changes['attributes'][$label] = $this->display($field, $new);
    }

    /** Human value for the activity log. */
    private function display(array $field, mixed $value): mixed
    {
        if ($value === self::USE_GLOBAL) {
            return $value;
        }

        return match ($field['type']) {
            'bool' => $value ? 'On' : 'Off',
            'select' => $field['options'][$value] ?? $value,
            'image' => $value ? 'Image' : 'None',
            default => $value,
        };
    }
}
