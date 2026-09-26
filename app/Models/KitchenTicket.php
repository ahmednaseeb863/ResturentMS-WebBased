<?php

namespace App\Models;

use App\Enums\KitchenStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\NeverDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kitchen order ticket (KOT): the lines of one send for one station, numbered per business day. */
class KitchenTicket extends Model
{
    use BelongsToBranch, HasPublicUuid, NeverDeleted;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => KitchenStatus::class,
            'business_date' => 'date',
            'number' => 'integer',
            'sent_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'printed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class, 'kitchen_station_id')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** "KOT-007" */
    public function code(): string
    {
        return 'KOT-'.str_pad((string) $this->number, 3, '0', STR_PAD_LEFT);
    }
}
