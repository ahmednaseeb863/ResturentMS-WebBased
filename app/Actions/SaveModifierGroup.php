<?php

namespace App\Actions;

use App\Http\Requests\ModifierGroupRequest;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Support\Activity;
use Illuminate\Support\Facades\DB;

/** Saves a modifier group, syncs its add-ons (removed ones trashed) and their recipes. */
class SaveModifierGroup
{
    public function handle(ModifierGroupRequest $request, ?ModifierGroup $group = null): ModifierGroup
    {
        return DB::transaction(function () use ($request, $group) {
            $group ??= new ModifierGroup;
            $group->fill($request->groupData())->save();

            $existing = $group->modifiers()->get()->keyBy('uuid');
            $keep = [];
            $changes = ['added' => [], 'updated' => [], 'removed' => [], 'recipes' => []];

            foreach ($request->modifiers() as $i => $data) {
                $modifier = $data['id'] ? $existing->get($data['id']) : null;
                $fields = ['name' => $data['name'], 'price' => $data['price'], 'is_active' => $data['is_active'], 'sort_order' => $i];

                if ($modifier) {
                    $modifier->fill($fields);
                    if ($modifier->isDirty(['name', 'price', 'is_active'])) {
                        $changes['updated'][] = $modifier->name;
                    }
                    $modifier->save();
                    $keep[] = $modifier->uuid;
                } else {
                    $modifier = Modifier::create([...$fields, 'modifier_group_id' => $group->id]);
                    $changes['added'][] = $modifier->name;
                }

                foreach ($modifier->syncRecipe($data['recipe']) as $line) {
                    $changes['recipes'][] = "{$modifier->name}: {$line}";
                }
            }

            foreach ($existing->toBase()->except($keep) as $modifier) {
                $modifier->trash();
                $changes['removed'][] = $modifier->name;
            }

            $changes = array_filter($changes);
            if ($changes && ! $group->wasRecentlyCreated) {
                Activity::log('options', $group, $changes);
            }

            return $group;
        });
    }
}
