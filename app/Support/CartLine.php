<?php

namespace App\Support;

use App\Enums\DiscountType;
use App\Models\Deal;
use App\Models\DealSlot;
use App\Models\DealSlotOption;
use App\Models\Discount;
use App\Models\MenuItem;
use App\Models\MenuItemVariant;
use App\Models\Modifier;
use App\Models\ReadyItem;
use Illuminate\Support\Collection;

/**
 * One checked line of a POS cart (built by OrderCart): what is sold, its price from the
 * menu (never from the browser), add-ons, deal picks and an optional line discount.
 */
class CartLine
{
    /**
     * @param  Collection<int, Modifier>  $modifiers
     * @param  list<array{slot: DealSlot, option: DealSlotOption}>  $picks
     * @param  array{preset: ?Discount, type: DiscountType, value: float, reason: ?string}|null  $discount
     */
    public function __construct(
        public string $type,
        public MenuItem|ReadyItem|Deal $item,
        public ?MenuItemVariant $variant,
        public Collection $modifiers,
        public array $picks,
        public int $quantity,
        public ?string $notes,
        public ?array $discount = null,
        public float $discountAmount = 0.0,
    ) {}

    /** Menu / size price; a deal's price plus the extra charge of its picks. */
    public function unitPrice(): float
    {
        if ($this->item instanceof Deal) {
            return round((float) $this->item->price + collect($this->picks)->sum(fn ($p) => (float) $p['option']->extra_price), 2);
        }

        return (float) ($this->variant?->price ?? $this->item->price);
    }

    public function modifiersTotal(): float
    {
        return round((float) $this->modifiers->sum('price'), 2);
    }

    public function gross(): float
    {
        return round(($this->unitPrice() + $this->modifiersTotal()) * $this->quantity, 2);
    }

    public function name(): string
    {
        return $this->item->name.($this->variant ? ' — '.$this->variant->name : '');
    }

    /** Stored in `orders.held_items` while the order is held (uuids only). */
    public function payload(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->item->uuid,
            'variant' => $this->variant?->uuid,
            'modifiers' => $this->modifiers->pluck('uuid')->values()->all(),
            'picks' => collect($this->picks)->map(fn ($p) => ['slot' => $p['slot']->uuid, 'option' => $p['option']->uuid])->all(),
            'quantity' => $this->quantity,
            'notes' => $this->notes,
            'discount' => $this->discount ? [
                'discount' => $this->discount['preset']?->uuid,
                'type' => $this->discount['type']->value,
                'value' => $this->discount['value'],
                'reason' => $this->discount['reason'],
            ] : null,
            'name' => $this->name(),
            'gross' => $this->gross(),
            'discount_amount' => $this->discountAmount,
        ];
    }
}
