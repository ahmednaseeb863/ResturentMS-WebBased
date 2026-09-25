<?php

namespace App\Support\Settings;

use App\Models\Branch;
use App\Models\Setting;
use App\Support\CurrentBranch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Reads settings (PLAN §4.5):  branch override → global value → built-in default.
 *
 *   setting('tax.rate')                  current branch
 *   setting('tax.rate', $branch)         a given branch (jobs, reports)
 *   app(SettingsResolver::class)->group('receipt')
 *
 * Stored rows are cached (one key for global, one per branch) and forgotten by
 * SaveSettings whenever a value changes, so a global change reaches every
 * branch that has not overridden it at once.
 */
class SettingsResolver
{
    public function __construct(private CurrentBranch $current) {}

    public function get(string $dotKey, Branch|int|null $branch = null): mixed
    {
        SettingsRegistry::field($dotKey); // throws on unknown keys

        $overrides = $this->branchOverrides($this->branchId($branch));

        if (array_key_exists($dotKey, $overrides)) {
            return $overrides[$dotKey];
        }

        return $this->globalValue($dotKey);
    }

    /** @return array<string, mixed> key => value for one group */
    public function group(string $group, Branch|int|null $branch = null): array
    {
        $values = [];
        foreach (array_keys(SettingsRegistry::fields($group)) as $key) {
            $values[$key] = $this->get("{$group}.{$key}", $branch);
        }

        return $values;
    }

    /** Global value (stored, else default) — what a branch gets when it does not override. */
    public function globalValue(string $dotKey): mixed
    {
        $stored = $this->globalRows();

        return array_key_exists($dotKey, $stored) ? $stored[$dotKey] : SettingsRegistry::defaults()[$dotKey];
    }

    /** @return array<string, mixed> "group.key" => value of stored global rows */
    public function globalRows(): array
    {
        return Cache::rememberForever('settings:global', fn () => $this->rows(Setting::query()->global()));
    }

    /** @return array<string, mixed> "group.key" => value of a branch's overrides */
    public function branchOverrides(?int $branchId): array
    {
        if ($branchId === null) {
            return [];
        }

        return Cache::rememberForever("settings:branch:{$branchId}", fn () => $this->rows(Setting::query()->ofBranch($branchId)));
    }

    public function forget(?int $branchId): void
    {
        Cache::forget($branchId === null ? 'settings:global' : "settings:branch:{$branchId}");
    }

    /** Public URL of an image setting (e.g. the logo), or null. */
    public function url(string $dotKey, Branch|int|null $branch = null): ?string
    {
        $path = $this->get($dotKey, $branch);

        return $path ? Storage::disk('public')->url($path) : null;
    }

    private function branchId(Branch|int|null $branch): ?int
    {
        return match (true) {
            $branch instanceof Branch => $branch->id,
            is_int($branch) => $branch,
            default => $this->current->id(),
        };
    }

    private function rows($query): array
    {
        return $query->get(['group', 'key', 'value'])
            ->mapWithKeys(fn (Setting $s) => ["{$s->group}.{$s->key}" => $s->value])
            ->all();
    }
}
