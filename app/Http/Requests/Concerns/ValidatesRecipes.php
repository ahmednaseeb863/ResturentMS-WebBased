<?php

namespace App\Http\Requests\Concerns;

use App\Models\RawMaterial;
use App\Models\Unit;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * Recipe lines sent by the recipe editor: `[{ raw_material: uuid, quantity, unit: uuid }]`.
 * The raw material must belong to the current branch (BelongsToBranch scope) and the
 * unit must be one it can be counted in (g / kg for a material kept in kg).
 *
 * The request lists every place a recipe can sit with recipePaths(), e.g.
 * ['recipe', 'variants.*.recipe'].
 */
trait ValidatesRecipes
{
    /** @return list<string> */
    abstract protected function recipePaths(): array;

    protected function recipeRules(): array
    {
        $rules = [];

        foreach ($this->recipePaths() as $path) {
            $rules[$path] = ['nullable', 'array', 'max:40'];
            $rules["{$path}.*.raw_material"] = ['required', 'uuid'];
            $rules["{$path}.*.quantity"] = ['required', 'numeric', 'gt:0', 'max:99999'];
            $rules["{$path}.*.unit"] = ['required', 'uuid'];
        }

        return $rules;
    }

    protected function recipeAttributes(): array
    {
        $names = [];
        foreach ($this->recipePaths() as $path) {
            $names["{$path}.*.raw_material"] = 'raw material';
            $names["{$path}.*.quantity"] = 'quantity';
            $names["{$path}.*.unit"] = 'unit';
        }

        return $names;
    }

    /** Checks every recipe in the request (call from after()). */
    protected function checkRecipes(Validator $validator): void
    {
        foreach ($this->recipePaths() as $path) {
            $this->checkRecipeAt($validator, $path, explode('.*.', $path));
        }
    }

    private function checkRecipeAt(Validator $validator, string $path, array $segments, string $prefix = ''): void
    {
        $head = array_shift($segments);
        $key = $prefix === '' ? $head : "{$prefix}.{$head}";

        if ($segments) {
            foreach (array_keys((array) $this->input($key, [])) as $i) {
                $this->checkRecipeAt($validator, $path, $segments, "{$key}.{$i}");
            }

            return;
        }

        $seen = [];
        foreach ((array) $this->input($key, []) as $i => $line) {
            $material = $this->recipeMaterials()->get($line['raw_material'] ?? '');
            $unit = $this->recipeUnits()->get($line['unit'] ?? '');

            if (! $material) {
                $validator->errors()->add("{$key}.{$i}.raw_material", 'Pick a raw material of this branch.');

                continue;
            }

            if (isset($seen[$material->id])) {
                $validator->errors()->add("{$key}.{$i}.raw_material", "“{$material->name}” is already in this recipe.");
            }
            $seen[$material->id] = true;

            if (! $unit || ! $unit->sameFamily($material->stockUnit)) {
                $validator->errors()->add("{$key}.{$i}.unit", "{$material->name} is counted in {$material->stockUnit->short_name} — pick a matching unit.");
            }
        }
    }

    /**
     * Validated lines → ids for HasRecipe::syncRecipe().
     *
     * @return list<array{raw_material_id: int, quantity: float, unit_id: int}>
     */
    public function recipeLines(?array $lines): array
    {
        return array_map(fn (array $line) => [
            'raw_material_id' => $this->recipeMaterials()->get($line['raw_material'])->id,
            'quantity' => round((float) $line['quantity'], 3),
            'unit_id' => $this->recipeUnits()->get($line['unit'])->id,
        ], array_values($lines ?? []));
    }

    protected function recipeMaterials(): Collection
    {
        return once(fn () => RawMaterial::query()->with('stockUnit')
            ->whereIn('uuid', $this->recipeUuids('raw_material'))->get()->keyBy('uuid'));
    }

    protected function recipeUnits(): Collection
    {
        return once(fn () => Unit::query()->whereIn('uuid', $this->recipeUuids('unit'))->get()->keyBy('uuid'));
    }

    private function recipeUuids(string $field): array
    {
        $uuids = [];
        foreach ($this->recipePaths() as $path) {
            $uuids[] = Arr::flatten((array) data_get($this->all(), "{$path}.*.{$field}"));
        }

        return array_values(array_unique(array_filter(array_merge(...$uuids), 'is_string')));
    }
}
