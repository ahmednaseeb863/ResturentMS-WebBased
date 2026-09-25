<?php

namespace App\Actions;

use App\Http\Requests\DealRequest;
use App\Models\Deal;
use App\Models\DealSlot;
use App\Models\DealSlotOption;
use App\Support\Activity;
use Illuminate\Support\Facades\DB;

/**
 * Saves a deal and syncs its slots and their options by uuid (removed ones trashed).
 * A change of contents is logged once as before / after text.
 */
class SaveDeal
{
    public function handle(DealRequest $request, ?Deal $deal = null): Deal
    {
        return DB::transaction(function () use ($request, $deal) {
            $deal ??= new Deal;
            $before = $deal->exists ? static::contents($deal) : null;

            $deal->fill($request->dealData())->save();

            $existing = $deal->slots()->with('options')->get()->keyBy('uuid');
            $keep = [];

            foreach ($request->slots() as $i => $data) {
                $slot = $data['id'] ? $existing->get($data['id']) : null;
                $fields = ['name' => $data['name'], 'quantity' => $data['quantity'], 'sort_order' => $i];

                if ($slot) {
                    $slot->update($fields);
                    $keep[] = $slot->uuid;
                } else {
                    $slot = DealSlot::create([...$fields, 'deal_id' => $deal->id]);
                }

                $this->syncOptions($slot, $data['options']);
            }

            foreach ($existing->toBase()->except($keep) as $slot) {
                $slot->trash();
            }

            $after = static::contents($deal);
            if ($before !== null && $before !== $after) {
                Activity::log('deal_setup', $deal, ['before' => $before, 'after' => $after]);
            }

            return $deal;
        });
    }

    private function syncOptions(DealSlot $slot, array $options): void
    {
        $existing = $slot->options()->get()->keyBy('uuid');
        $keep = [];

        foreach ($options as $i => $data) {
            $option = $data['id'] ? $existing->get($data['id']) : null;
            $fields = [
                'sellable_type' => $data['sellable_type'],
                'sellable_id' => $data['sellable_id'],
                'variant_id' => $data['variant_id'],
                'extra_price' => $data['extra_price'],
                'is_default' => $data['is_default'],
                'sort_order' => $i,
            ];

            if ($option) {
                $option->update($fields);
                $keep[] = $option->uuid;
            } else {
                DealSlotOption::create([...$fields, 'deal_slot_id' => $slot->id]);
            }
        }

        foreach ($existing->toBase()->except($keep) as $option) {
            $option->trash();
        }
    }

    /** "Burger: Zinger Burger" / "Drink ×2: Coke 1.5L or Sprite (+50)" per slot. */
    public static function contents(Deal $deal): array
    {
        return $deal->slots()->with('options.sellable', 'options.variant')->get()->map(function (DealSlot $slot) {
            $options = $slot->options->map(fn (DealSlotOption $o) => $o->label().((float) $o->extra_price ? ' (+'.(float) $o->extra_price.')' : ''));

            return $slot->name.($slot->quantity > 1 ? " ×{$slot->quantity}" : '').': '.$options->join(' or ');
        })->all();
    }
}
