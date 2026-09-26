<?php

namespace App\Support;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\OfferStatus;
use App\Enums\OrderType;
use App\Models\Deal;
use App\Models\DealSlotOption;
use App\Models\Discount;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\OrderDiscount;
use App\Models\ReadyItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Reads the cart the POS sends (uuids only) into checked CartLines: items of this branch,
 * active, not sold out, sold for the order type; sizes and add-on rules; deals available
 * now with one pick per slot; line discounts. Prices always come from the menu.
 *
 *   $lines = OrderCart::read($request->validated('items'), OrderType::DineIn);
 *
 * Errors are keyed `items.{i}` and name the item ("“Zinger” is sold out.").
 */
class OrderCart
{
    /** @return list<CartLine> */
    public static function read(array $items, OrderType $type, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $items = array_values($items);
        $models = static::load($items);
        $businessDate = BusinessDate::for(null, $at);
        $lines = [];
        $errors = [];

        foreach ($items as $i => $data) {
            try {
                $lines[] = static::line($data, $models, $type, $at, $businessDate);
            } catch (CartError $e) {
                $errors["items.{$i}"] = $e->getMessage();
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }

    /**
     * When ready items must not be sold beyond stock (`inventory.out_of_stock` = block),
     * refuse lines asking for more than is left (a deal's drinks count too).
     *
     * @param  list<CartLine>  $lines
     */
    public static function checkStock(array $lines): void
    {
        if (setting('inventory.out_of_stock') !== 'block') {
            return;
        }

        $needed = [];
        foreach ($lines as $i => $line) {
            foreach (static::readyItemsOf($line) as [$item, $qty]) {
                $needed[$item->id] ??= ['item' => $item, 'qty' => 0, 'line' => $i];
                $needed[$item->id]['qty'] += $qty;
            }
        }

        $errors = [];
        foreach ($needed as ['item' => $item, 'qty' => $qty, 'line' => $i]) {
            $left = (float) $item->fresh()->current_stock;
            if ($qty > $left) {
                $errors["items.{$i}"] = $left > 0
                    ? 'Only '.Qty::format($left)." of “{$item->name}” left in stock."
                    : "“{$item->name}” is out of stock.";
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return list<array{0: ReadyItem, 1: int}> ready items the line sells and how many */
    public static function readyItemsOf(CartLine $line): array
    {
        if ($line->item instanceof ReadyItem) {
            return [[$line->item, $line->quantity]];
        }
        if ($line->item instanceof Deal) {
            return collect($line->picks)
                ->filter(fn ($p) => $p['option']->sellable instanceof ReadyItem)
                ->map(fn ($p) => [$p['option']->sellable, $p['slot']->quantity * $line->quantity])
                ->values()->all();
        }

        return [];
    }

    // ── internals ────────────────────────────────────────────────────────

    /** Everything the cart names, loaded in a few queries (current branch only). */
    private static function load(array $items): array
    {
        $ids = fn (string $type) => collect($items)->where('type', $type)->pluck('id')->filter()->unique()->all();
        $discounts = collect($items)->pluck('discount.discount')->filter()->unique()->all();

        return [
            'menu_item' => MenuItem::query()->whereIn('uuid', $ids('menu_item'))
                ->with(['variants', 'modifierGroups.modifiers'])->get()->keyBy('uuid'),
            'ready_item' => ReadyItem::query()->whereIn('uuid', $ids('ready_item'))->get()->keyBy('uuid'),
            'deal' => Deal::query()->whereIn('uuid', $ids('deal'))
                ->with(['slots.options.sellable', 'slots.options.variant'])->get()->keyBy('uuid'),
            'discount' => Discount::query()->whereIn('uuid', $discounts)->get()->keyBy('uuid'),
        ];
    }

    private static function line(array $data, array $models, OrderType $type, CarbonImmutable $at, string $businessDate): CartLine
    {
        $item = $models[$data['type']]->get($data['id'] ?? '')
            ?? throw new CartError('An item in the cart is no longer on the menu — remove it.');
        $name = "“{$item->name}”";

        $variant = null;
        $modifiers = collect();
        $picks = [];

        if ($item instanceof Deal) {
            if (! $item->isAvailableAt($at, $type)) {
                throw new CartError("The deal {$name} is not available ".($item->isAvailableAt($at) ? 'for '.strtolower($type->label()) : 'now').'.');
            }
            $picks = static::picks($item, $data['picks'] ?? []);
        } else {
            static::checkSellable($item, $type);

            if ($item instanceof MenuItem) {
                $variant = static::variant($item, $data['variant'] ?? null);
                $modifiers = static::modifiers($item, $data['modifiers'] ?? []);
            }
        }

        $line = new CartLine(
            type: $data['type'],
            item: $item,
            variant: $variant,
            modifiers: $modifiers,
            picks: $picks,
            quantity: (int) $data['quantity'],
            notes: filled($data['notes'] ?? null) ? trim($data['notes']) : null,
        );

        if (! empty($data['discount'])) {
            $line->discount = static::discount($data['discount'], $models['discount'], $businessDate, DiscountScope::Item, $name);
            $line->discountAmount = OrderDiscount::calculate(
                $line->discount['type'], $line->discount['value'], $line->gross(),
                $line->discount['preset']?->max_amount, $line->discount['preset']?->min_order_amount,
            );
        }

        return $line;
    }

    private static function checkSellable(MenuItem|ReadyItem $item, OrderType $type, ?string $in = null): void
    {
        $name = "“{$item->name}”".($in ? " in {$in}" : '');

        if (! $item->is_active || $item->isTrashed()) {
            throw new CartError("{$name} is no longer on sale.");
        }
        if ($item instanceof MenuItem && $item->is_sold_out) {
            throw new CartError("{$name} is sold out today.");
        }
        if (! in_array($type->value, $item->available_for ?? [], true)) {
            throw new CartError("{$name} is not sold for ".strtolower($type->label()).'.');
        }
    }

    private static function variant(MenuItem $item, ?string $uuid)
    {
        if ($item->variants->isEmpty()) {
            return null;
        }

        return $item->variants->firstWhere('uuid', $uuid)
            ?? throw new CartError("Pick a size for “{$item->name}”.");
    }

    /** Add-ons of the item's active groups, each group within its min / max. */
    private static function modifiers(MenuItem $item, array $uuids): Collection
    {
        $groups = $item->modifierGroups->filter(fn (ModifierGroup $g) => $g->is_active);
        $available = $groups->flatMap(fn (ModifierGroup $g) => $g->modifiers->filter(fn ($m) => $m->is_active));
        $picked = collect(array_unique($uuids))->map(
            fn ($uuid) => $available->firstWhere('uuid', $uuid)
                ?? throw new CartError("An add-on of “{$item->name}” is no longer available — pick again.")
        );

        foreach ($groups as $group) {
            $count = $picked->where('modifier_group_id', $group->id)->count();

            if ($count < $group->min_select) {
                throw new CartError("Pick {$group->name} for “{$item->name}”".($group->min_select > 1 ? " ({$group->min_select})" : '').'.');
            }
            if ($group->max_select !== null && $count > $group->max_select) {
                throw new CartError("“{$item->name}”: pick at most {$group->max_select} of {$group->name}.");
            }
        }

        return $picked->values();
    }

    /** One pick per slot; a slot with a single option is picked automatically. */
    private static function picks(Deal $deal, array $given): array
    {
        $given = collect($given)->pluck('option', 'slot');
        $picks = [];

        foreach ($deal->slots as $slot) {
            $options = $slot->options;
            $option = $options->count() === 1 && ! $given->has($slot->uuid)
                ? $options->first()
                : $options->firstWhere('uuid', $given->get($slot->uuid));

            if (! $option) {
                throw new CartError("Pick {$slot->name} for the deal “{$deal->name}”.");
            }
            if (! $option->sellable) {
                throw new CartError("A pick of the deal “{$deal->name}” is no longer on the menu.");
            }

            static::checkPick($option, $deal);
            $picks[] = ['slot' => $slot, 'option' => $option];
        }

        return $picks;
    }

    private static function checkPick(DealSlotOption $option, Deal $deal): void
    {
        $item = $option->sellable;
        $name = "“{$item->name}” in the deal “{$deal->name}”";

        if (! $item->is_active || $item->isTrashed() || ($item instanceof MenuItem && $item->is_sold_out)) {
            throw new CartError("{$name} is not available — pick another.");
        }
    }

    /** @return array{preset: ?Discount, type: DiscountType, value: float, reason: ?string} */
    public static function discount(array $data, Collection $presets, string $businessDate, DiscountScope $scope, string $on = 'the order'): array
    {
        if (! empty($data['discount'])) {
            $preset = $presets->get($data['discount']);

            if (! $preset || $preset->statusOn($businessDate) !== OfferStatus::Active) {
                throw new CartError("The discount on {$on} is not running any more.");
            }
            if ($preset->applies_to !== $scope) {
                throw new CartError("“{$preset->name}” is for ".strtolower($preset->applies_to->label()).'.');
            }

            return ['preset' => $preset, 'type' => $preset->type, 'value' => (float) $preset->value, 'reason' => $data['reason'] ?? null];
        }

        $type = DiscountType::tryFrom((string) ($data['type'] ?? ''));
        $value = (float) ($data['value'] ?? 0);

        if (! $type || $value <= 0 || ($type === DiscountType::Percent && $value > 100)) {
            throw new CartError("Enter a valid discount for {$on}.");
        }
        if (blank($data['reason'] ?? null)) {
            throw new CartError("Say why {$on} is discounted.");
        }

        return ['preset' => null, 'type' => $type, 'value' => round($value, 2), 'reason' => trim($data['reason'])];
    }
}
