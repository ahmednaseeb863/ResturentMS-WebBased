<?php

namespace App\Models;

use App\Enums\ConsumptionStatus;
use App\Enums\KitchenStatus;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\NeverDeleted;
use App\Models\Concerns\TrashScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A sent order line: menu item (size + add-ons), ready item, or a deal (parent line with
 * its picks as child lines at price 0). Names and prices are copied, so menu changes
 * never change old orders. Never deleted — voided (with a reason) instead.
 */
class OrderItem extends Model
{
    use HasPublicUuid, NeverDeleted;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'modifiers_total' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'kitchen_status' => KitchenStatus::class,
            'consumption_status' => ConsumptionStatus::class,
            'sent_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
            'voided_at' => 'datetime',
            'void_wasted' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_order_item_id');
    }

    /** Picks of a deal line. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_order_item_id')->orderBy('id');
    }

    public function sellable(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes([TrashScope::class]);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(MenuItemVariant::class, 'variant_id')->withTrashed();
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class)->orderBy('id');
    }

    public function discount(): HasOne
    {
        return $this->hasOne(OrderDiscount::class)->latestOfMany();
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class, 'kitchen_station_id')->withTrashed();
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(KitchenTicket::class, 'kitchen_ticket_id');
    }

    /** Raw materials confirmed for this line (kitchen). */
    public function consumptions(): HasMany
    {
        return $this->hasMany(OrderItemConsumption::class)->orderBy('id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'sent_by')->withTrashed();
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'voided_by')->withTrashed();
    }

    public function scopeLive(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function isDeal(): bool
    {
        return $this->sellable_type === 'deal';
    }

    /** "Zinger Burger — Large" */
    public function fullName(): string
    {
        return $this->item_name.($this->variant_name ? ' — '.$this->variant_name : '');
    }

    /** (unit price + add-ons) × qty, before the line discount. */
    public function gross(): float
    {
        return round(((float) $this->unit_price + (float) $this->modifiers_total) * $this->quantity, 2);
    }
}
