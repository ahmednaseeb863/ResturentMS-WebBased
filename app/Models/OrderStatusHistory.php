<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who moved an order to which status, when (append-only). */
class OrderStatusHistory extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['from_status' => OrderStatus::class, 'to_status' => OrderStatus::class];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class)->withTrashed();
    }
}
