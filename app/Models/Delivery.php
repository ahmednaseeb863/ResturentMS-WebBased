<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\NeverDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a delivery order goes. The address is copied from the customer's saved address
 * (or typed at the POS), so editing the customer later never changes the order.
 */
class Delivery extends Model
{
    use BelongsToBranch, HasPublicUuid, NeverDeleted;

    protected $guarded = ['id'];

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

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rider_id')->withTrashed();
    }
}
