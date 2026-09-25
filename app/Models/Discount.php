<?php

namespace App\Models;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasOfferDates;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A predefined discount the cashier can pick (PLAN §4.8): percent or fixed, on the whole
 * order or one item, optionally capped, with a minimum amount and a manager approval.
 * Manual (typed-in) discounts at the POS use their own permission instead.
 */
class Discount extends Model
{
    use BelongsToBranch, HasFactory, HasOfferDates, HasPublicUuid, LogsActivity, Trashable;

    protected $fillable = [
        'name', 'type', 'value', 'applies_to', 'max_amount', 'min_order_amount',
        'starts_on', 'ends_on', 'requires_approval', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => DiscountType::class,
            'applies_to' => DiscountScope::class,
            'value' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'requires_approval' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Discount on this amount: 0 below the minimum, never above the cap or the amount itself. */
    public function amountOn(float $amount): float
    {
        if ($this->min_order_amount !== null && $amount < (float) $this->min_order_amount) {
            return 0.0;
        }

        $off = $this->type === DiscountType::Percent
            ? $amount * (float) $this->value / 100
            : (float) $this->value;

        if ($this->max_amount !== null) {
            $off = min($off, (float) $this->max_amount);
        }

        return round(min($off, $amount), 2);
    }

    /** "12.5%" / "200" — for flash messages and logs (React formats money itself). */
    public function valueText(): string
    {
        $value = rtrim(rtrim(number_format((float) $this->value, 2, '.', ''), '0'), '.');

        return $this->type === DiscountType::Percent ? "{$value}%" : $value;
    }
}
