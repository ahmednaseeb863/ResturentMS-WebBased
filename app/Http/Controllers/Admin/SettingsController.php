<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SaveSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsRequest;
use App\Models\Branch;
use App\Models\Setting;
use App\Support\CurrentBranch;
use App\Support\Settings\SettingsRegistry;
use App\Support\Settings\SettingsResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings screens (PLAN §4.5): the current branch's settings (each field "Use global"
 * or overridden) and the global settings. Saving is per group; the branch routes are
 * one per group (`settings.branch.tax`…) so roles can allow some groups only.
 */
class SettingsController extends Controller
{
    public function __construct(private SettingsResolver $resolver) {}

    public function branch(Request $request, CurrentBranch $current): Response|RedirectResponse
    {
        $branch = $current->get();

        if (! $branch) {
            return redirect()->route('settings.global');
        }

        return Inertia::render('settings/Index', [
            'scope' => 'branch',
            'branch' => ['id' => $branch->uuid, 'name' => $branch->name],
            'groups' => $this->groups($branch),
            'group' => $this->pickedGroup($request),
        ]);
    }

    public function global(Request $request): Response
    {
        return Inertia::render('settings/Index', [
            'scope' => 'global',
            'branch' => null,
            'groups' => $this->groups(null),
            'group' => $this->pickedGroup($request),
        ]);
    }

    public function updateBranch(SettingsRequest $request, SaveSettings $save): RedirectResponse
    {
        return $this->saved($request, $save->handle($request));
    }

    public function updateGlobal(SettingsRequest $request, SaveSettings $save): RedirectResponse
    {
        return $this->saved($request, $save->handle($request));
    }

    private function saved(SettingsRequest $request, int $changed): RedirectResponse
    {
        $label = SettingsRegistry::groups()[$request->group()]['label'];
        $where = $request->isGlobal() ? 'global' : "“{$request->branch()->name}”";

        return back()->with('success', $changed
            ? "{$label} settings saved for {$where}."
            : 'Nothing changed.');
    }

    private function pickedGroup(Request $request): string
    {
        $group = (string) $request->query('group');

        return SettingsRegistry::hasGroup($group) ? $group : SettingsRegistry::groupKeys()[0];
    }

    /** Groups → sections → fields with the value used, the global value and where it comes from. */
    private function groups(?Branch $branch): array
    {
        $overrides = $branch ? $this->resolver->branchOverrides($branch->id) : [];
        $globalRows = $this->resolver->globalRows();
        $overrideCounts = $branch ? [] : $this->overrideCounts();

        return collect(SettingsRegistry::groups())->map(fn (array $group, string $groupKey) => [
            'key' => $groupKey,
            'label' => $group['label'],
            'description' => $group['description'],
            'sections' => collect($group['sections'])->map(fn (array $section) => [
                'title' => $section['title'],
                'fields' => collect($section['fields'])->map(function (array $field, string $key) use ($groupKey, $branch, $overrides, $globalRows, $overrideCounts) {
                    $dotKey = "{$groupKey}.{$key}";
                    $global = $this->resolver->globalValue($dotKey);
                    $overridden = $branch
                        ? array_key_exists($dotKey, $overrides)
                        : array_key_exists($dotKey, $globalRows);

                    return [
                        'key' => $key,
                        'label' => $field['label'],
                        'type' => $field['type'],
                        'sub' => $field['sub'] ?? null,
                        'suffix' => $field['suffix'] ?? null,
                        'min' => $field['min'] ?? null,
                        'max' => $field['max'] ?? null,
                        'required' => $field['required'] ?? false,
                        'options' => isset($field['options'])
                            ? collect($field['options'])->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values()
                            : null,
                        // branch: override (else global) · global: stored (else default)
                        'value' => $this->present($field, $branch && $overridden ? $overrides[$dotKey] : $global),
                        // what "Use global" gives (branch) / the built-in default (global)
                        'inherited' => $this->present($field, $branch ? $global : $field['default']),
                        'overridden' => $overridden,
                        'override_count' => $overrideCounts[$dotKey] ?? 0,
                    ];
                })->values(),
            ])->values(),
        ])->values()->all();
    }

    private function present(array $field, mixed $value): mixed
    {
        return $field['type'] === 'image' && $value ? Storage::disk('public')->url($value) : $value;
    }

    /** @return array<string, int> "group.key" => number of branches overriding it */
    private function overrideCounts(): array
    {
        return Setting::query()
            ->whereNotNull('branch_id')
            ->whereHas('branch')
            ->select('group', 'key', DB::raw('count(*) as total'))
            ->groupBy('group', 'key')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->group}.{$row->key}" => (int) $row->total])
            ->all();
    }
}
