<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\NeverDeleted;
use App\Support\LiveUpdates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a delivery order goes and who takes it (PLAN §4.14). The address is copied from the
 * customer's saved address (or typed at the POS), so editing the customer later never
 * changes the order. Moves only through AssignRider / UpdateDeliveryStatus; cash the rider
 * collected (`cash_collected`) is held by the rider until SettleRiderCash.
 */
class Delivery extends Model
{
    use BelongsToBranch, HasPublicUuid, NeverDeleted;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // deliveries board, riders screen and rider panels reload on their next poll
        static::saved(fn (self $delivery) => LiveUpdates::bump('deliveries', $delivery->branch_id));
    }

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'fee' => 'decimal:2',
            'cash_to_collect' => 'decimal:2',
            'cash_collected' => 'decimal:2',
            'assigned_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'returned_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function savedAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class, 'user_address_id')->withTrashed();
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id')->withTrashed();
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rider_id')->withTrashed();
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_by')->withTrashed();
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class, 'settlement_movement_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', DeliveryStatus::active());
    }

    /** Delivered with cash the rider still holds. */
    public function scopeUnsettled(Builder $query): void
    {
        $query->where('status', DeliveryStatus::Delivered)->whereNull('settled_at')->where('cash_collected', '>', 0);
    }

    public function holdsCash(): bool
    {
        return $this->status === DeliveryStatus::Delivered && $this->settled_at === null && (float) $this->cash_collected > 0;
    }

    /** Cash riders of the branch still hold (dashboard, shift close). */
    public static function cashHeld(int $branchId, ?int $riderId = null): float
    {
        return round((float) static::query()->withoutGlobalScope('branch')
            ->where('branch_id', $branchId)
            ->when($riderId, fn ($q) => $q->where('rider_id', $riderId))
            ->unsettled()
            ->sum('cash_collected'), 2);
    }
}
