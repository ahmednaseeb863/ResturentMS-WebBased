<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Discount on an order (order_item_id null) or one line. Type, value, cap and minimum are
 * copied from the predefined discount (or typed in), so editing the discount later never
 * changes the order. A replaced / removed discount is trashed.
 */
class OrderDiscount extends Model
{
    use Trashable;

    protected $guarded = ['id'];

    /** The order logs discount changes itself. */
    protected bool $logTrashActivity = false;

    protected function casts(): array
    {
        return [
            'type' => DiscountType::class,
            'value' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'min_amount' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class)->withTrashed();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by')->withTrashed();
    }

    /** Amount off `$base`: 0 below the minimum, never above the cap or the base. */
    public function amountOn(float $base): float
    {
        return static::calculate($this->type, (float) $this->value, $base, $this->max_amount, $this->min_amount);
    }

    public static function calculate(DiscountType $type, float $value, float $base, mixed $max = null, mixed $min = null): float
    {
        if ($base <= 0 || ($min !== null && $base < (float) $min)) {
            return 0.0;
        }

        $off = $type === DiscountType::Percent ? $base * $value / 100 : $value;

        if ($max !== null) {
            $off = min($off, (float) $max);
        }

        return round(max(0, min($off, $base)), 2);
    }

    /** "10%" / "Rs 200" */
    public function valueText(): string
    {
        $value = rtrim(rtrim(number_format((float) $this->value, 2, '.', ''), '0'), '.');

        return $this->type === DiscountType::Percent ? "{$value}%" : money($value);
    }

    public function trashLabel(): string
    {
        return $this->name;
    }
}
