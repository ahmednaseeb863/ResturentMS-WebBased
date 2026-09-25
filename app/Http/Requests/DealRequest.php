<?php

namespace App\Http\Requests;

use App\Enums\OrderType;
use App\Http\Requests\Concerns\HandlesImage;
use App\Models\Deal;
use App\Models\MenuItem;
use App\Models\MenuItemVariant;
use App\Models\ReadyItem;
use App\Rules\UniqueWithTrash;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A deal with its slots and each slot's options. Slots and options are sent as lists:
 * existing rows carry their uuid, new ones none; the ones left out are trashed.
 * An option is `type` (menu_item / ready_item) + `item` uuid, and for a menu item an
 * optional `variant` uuid that fixes the size.
 */
class DealRequest extends FormRequest
{
    use HandlesImage;

    public const TYPES = ['menu_item' => MenuItem::class, 'ready_item' => ReadyItem::class];

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', new UniqueWithTrash('deals', 'name', $this->deal()?->id, 'deal', ['branch_id' => app(CurrentBranch::class)->id()])],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'days_of_week' => ['nullable', 'array', 'max:7'],
            'days_of_week.*' => ['integer', 'between:1,7', 'distinct'],
            'start_time' => ['nullable', 'required_with:end_time', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_with:start_time', 'date_format:H:i', 'different:start_time'],
            'available_for' => ['required', 'array', 'min:1'],
            'available_for.*' => [Rule::in(OrderType::values())],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
            'slots' => ['required', 'array', 'min:1', 'max:12'],
            'slots.*.id' => ['nullable', 'uuid'],
            'slots.*.name' => ['required', 'string', 'max:80', 'distinct:ignore_case'],
            'slots.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'slots.*.options' => ['required', 'array', 'min:1', 'max:40'],
            'slots.*.options.*.id' => ['nullable', 'uuid'],
            'slots.*.options.*.type' => ['required', Rule::in(array_keys(self::TYPES))],
            'slots.*.options.*.item' => ['required', 'uuid'],
            'slots.*.options.*.variant' => ['nullable', 'uuid'],
            'slots.*.options.*.extra_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'slots.*.options.*.is_default' => ['boolean'],
            ...$this->imageRules(),
        ];
    }

    public function attributes(): array
    {
        return [
            'ends_on' => 'end date',
            'starts_on' => 'start date',
            'end_time' => 'end time',
            'start_time' => 'start time',
            'slots.*.name' => 'slot name',
            'slots.*.quantity' => 'quantity',
            'slots.*.options.*.item' => 'item',
            'slots.*.options.*.extra_price' => 'extra price',
        ];
    }

    public function messages(): array
    {
        return [
            'available_for.required' => 'Pick at least one order type.',
            'slots.required' => 'Add at least one item to the deal.',
            'slots.*.options.required' => 'Pick at least one item for this slot.',
            'slots.*.name.distinct' => 'Two slots have the same name.',
            'ends_on.after_or_equal' => 'The end date cannot be before the start date.',
            'end_time.different' => 'Start and end time cannot be the same.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return; // the checks below read the slots structure
            }

            $knownSlots = $this->deal()?->slots()->pluck('uuid')->all() ?? [];
            $knownOptions = $this->deal()
                ? $this->deal()->slots()->with('options')->get()->flatMap->options->pluck('uuid')->all()
                : [];

            foreach ($this->input('slots') as $s => $slot) {
                if (! empty($slot['id']) && ! in_array($slot['id'], $knownSlots, true)) {
                    $validator->errors()->add("slots.{$s}.name", 'This slot no longer exists — reload the page.');
                }

                $seen = [];
                foreach ($slot['options'] as $o => $option) {
                    $key = "slots.{$s}.options.{$o}";
                    $item = $this->sellable($option['type'], $option['item']);

                    if (! empty($option['id']) && ! in_array($option['id'], $knownOptions, true)) {
                        $validator->errors()->add("{$key}.item", 'This option no longer exists — reload the page.');
                    }
                    if (! $item) {
                        $validator->errors()->add("{$key}.item", 'Pick a menu item or ready item of this branch.');

                        continue;
                    }
                    if (! empty($option['variant']) && ! $this->variant($item, $option['variant'])) {
                        $validator->errors()->add("{$key}.variant", "Pick a size of “{$item->name}”.");
                    }

                    $pick = $option['type'].$item->id.'/'.($option['variant'] ?? '');
                    if (in_array($pick, $seen, true)) {
                        $validator->errors()->add("{$key}.item", "“{$item->name}” is already in this slot.");
                    }
                    $seen[] = $pick;
                }
            }
        }];
    }

    public function deal(): ?Deal
    {
        return $this->route('deal');
    }

    /** Menu items / ready items of the current branch that the options refer to, keyed "type:uuid". */
    private function sellables(): Collection
    {
        return once(function () {
            $options = collect($this->input('slots', []))->pluck('options')->flatten(1)->filter(fn ($o) => is_array($o));
            $found = collect();

            foreach (self::TYPES as $type => $model) {
                $uuids = $options->where('type', $type)->pluck('item')->filter(fn ($u) => is_string($u))->unique()->values();
                if ($uuids->isEmpty()) {
                    continue;
                }

                $query = $model::query()->whereIn('uuid', $uuids);
                if ($model === MenuItem::class) {
                    $query->with('variants');
                }
                $query->get()->each(fn ($item) => $found->put("{$type}:{$item->uuid}", $item));
            }

            return $found;
        });
    }

    private function sellable(string $type, string $uuid): MenuItem|ReadyItem|null
    {
        return $this->sellables()->get("{$type}:{$uuid}");
    }

    private function variant(MenuItem|ReadyItem $item, string $uuid): ?MenuItemVariant
    {
        return $item instanceof MenuItem ? $item->variants->firstWhere('uuid', $uuid) : null;
    }

    public function dealData(): array
    {
        $days = array_map('intval', $this->validated('days_of_week') ?? []);
        sort($days);

        return [
            'name' => $this->validated('name'),
            'description' => $this->validated('description'),
            'price' => $this->validated('price'),
            'starts_on' => $this->validated('starts_on'),
            'ends_on' => $this->validated('ends_on'),
            // all seven days = no restriction
            'days_of_week' => $days && count($days) < 7 ? array_values(array_unique($days)) : null,
            'start_time' => $this->validated('start_time'),
            'end_time' => $this->validated('end_time'),
            'available_for' => array_values(array_unique($this->validated('available_for'))),
            'sort_order' => (int) ($this->validated('sort_order') ?? 0),
            'is_active' => $this->boolean('is_active', true),
            ...$this->imageData('deals'),
        ];
    }

    /**
     * Slots with resolved options; exactly one default option per slot.
     *
     * @return list<array{id: ?string, name: string, quantity: int, options: list<array>}>
     */
    public function slots(): array
    {
        return array_map(function (array $slot) {
            $options = array_values($slot['options']);
            $default = collect($options)->search(fn ($o) => filter_var($o['is_default'] ?? false, FILTER_VALIDATE_BOOL));
            $default = $default === false ? 0 : $default;

            return [
                'id' => $slot['id'] ?? null,
                'name' => $slot['name'],
                'quantity' => (int) $slot['quantity'],
                'options' => array_map(function (array $o, int $i) use ($default) {
                    $item = $this->sellable($o['type'], $o['item']);

                    return [
                        'id' => $o['id'] ?? null,
                        'sellable_type' => $o['type'],
                        'sellable_id' => $item->id,
                        'variant_id' => empty($o['variant']) ? null : $this->variant($item, $o['variant'])->id,
                        'extra_price' => $o['extra_price'] ?? 0,
                        'is_default' => $i === $default,
                    ];
                }, $options, array_keys($options)),
            ];
        }, array_values($this->validated('slots')));
    }
}
