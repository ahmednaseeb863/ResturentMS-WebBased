<?php

namespace App\Http\Requests;

use App\Models\Branch;
use App\Support\CurrentBranch;
use App\Support\Settings\SettingsRegistry;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Save one settings group, globally or for the current branch.
 *
 *   values[key]     new value (branch scope: only for overridden keys)
 *   overrides[]     branch scope: keys that override the global value; the rest "use global"
 *   files[key]      new image for an image field; remove[key] = 1 clears it
 *
 * The route decides the scope and the group (`settings.global.update` /
 * `settings.branch.<group>`), so permission per group comes from the route name.
 */
class SettingsRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'overrides' => ['array'],
            'overrides.*' => ['string', Rule::in(array_keys($this->fields()))],
        ];

        foreach ($this->fields() as $key => $field) {
            if (! $this->isStored($key)) {
                continue;
            }

            if ($field['type'] === 'image') {
                $rules["files.{$key}"] = SettingsRegistry::rules($field);
                $rules["remove.{$key}"] = ['boolean'];
            } else {
                $rules["values.{$key}"] = SettingsRegistry::rules($field);
            }
        }

        return $rules;
    }

    public function attributes(): array
    {
        $attributes = [];
        foreach ($this->fields() as $key => $field) {
            $attributes["values.{$key}"] = strtolower($field['label']);
            $attributes["files.{$key}"] = strtolower($field['label']);
        }

        return $attributes;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->group() === 'kitchen' && ! $validator->errors()->hasAny(['values.amber_after', 'values.red_after'])) {
                if ((int) $this->effective('red_after') <= (int) $this->effective('amber_after')) {
                    $validator->errors()->add($this->isStored('red_after') ? 'values.red_after' : 'values.amber_after', 'Red must come after amber.');
                }
            }
        }];
    }

    public function group(): string
    {
        return $this->route('group');
    }

    public function isGlobal(): bool
    {
        return $this->route()->getName() === 'settings.global.update';
    }

    /** Branch being edited; null for global settings. */
    public function branch(): ?Branch
    {
        return $this->isGlobal() ? null : app(CurrentBranch::class)->get();
    }

    /** @return array<string, array> */
    public function fields(): array
    {
        return SettingsRegistry::fields($this->group());
    }

    /** Is this key saved by this request? Global: every key. Branch: overridden keys only. */
    public function isStored(string $key): bool
    {
        return $this->isGlobal() || in_array($key, (array) $this->input('overrides', []), true);
    }

    /** @return array<string, mixed> key => cast value, for stored non-image keys */
    public function values(): array
    {
        $values = [];
        foreach ($this->fields() as $key => $field) {
            if ($field['type'] !== 'image' && $this->isStored($key)) {
                $values[$key] = SettingsRegistry::cast($field, $this->validated("values.{$key}"));
            }
        }

        return $values;
    }

    /** Value after this save: the submitted one, else what the branch inherits. */
    private function effective(string $key): mixed
    {
        return $this->isStored($key)
            ? $this->input("values.{$key}")
            : app(SettingsResolver::class)->globalValue("{$this->group()}.{$key}");
    }
}
