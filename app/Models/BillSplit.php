<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One part of a split bill (App\Actions\SplitBill): "Guest 2" pays `amount` — an equal
 * share, or the items in `items` ([{order_item_id, quantity}]) with their share of the
 * service charge, tax and discount. Replaced parts are trashed; a paid part never changes.
 */
class BillSplit extends Model
{
    use BelongsToBranch, HasPublicUuid, Trashable;

    protected $guarded = ['id'];

    /** The order logs split changes itself. */
    protected bool $logTrashActivity = false;

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'amount' => 'decimal:2',
            'items' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    /** Money kept for this part (payments − refunds); uses the loaded payments when there. */
    public function paid(): float
    {
        $payments = $this->relationLoaded('payments') ? $this->payments : $this->payments()->get();

        return round($payments->sum(fn (Payment $p) => (float) $p->amount - (float) $p->refunded_total), 2);
    }

    public function due(): float
    {
        return max(0, round((float) $this->amount - $this->paid(), 2));
    }

    public function trashLabel(): string
    {
        return $this->label;
    }
}
