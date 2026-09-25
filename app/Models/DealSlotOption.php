<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\Trashable;
use App\Models\Concerns\TrashScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One pick of a deal slot: a menu item (optionally a fixed size) or a ready item,
 * with an optional extra charge ("Large drink +100").
 */
class DealSlotOption extends Model
{
    use HasPublicUuid, Trashable;

    protected $fillable = ['deal_slot_id', 'sellable_type', 'sellable_id', 'variant_id', 'extra_price', 'is_default', 'sort_order'];

    protected bool $logTrashActivity = false;

    protected function casts(): array
    {
        return ['extra_price' => 'decimal:2', 'is_default' => 'boolean', 'sort_order' => 'integer'];
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(DealSlot::class, 'deal_slot_id');
    }

    public function sellable(): MorphTo
    {
        // MorphTo::withTrashed() only knows SoftDeletes; drop our trash scope instead
        // (withoutGlobalScopes is replayed on every morph type when eager loading)
        return $this->morphTo()->withoutGlobalScopes([TrashScope::class]);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(MenuItemVariant::class, 'variant_id')->withTrashed();
    }

    /** "Zinger Burger — Large" */
    public function label(): string
    {
        return $this->sellable?->name.($this->variant ? ' — '.$this->variant->name : '');
    }

    /** Menu price of what is picked (the size's price when fixed). */
    public function regularPrice(): float
    {
        return (float) ($this->variant?->price ?? $this->sellable?->price ?? 0);
    }
}
