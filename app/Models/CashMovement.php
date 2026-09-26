<?php

namespace App\Models;

use App\Enums\CashMovementType;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Cash in / out of a shift's drawer other than sales (append-only; a mistake is fixed
 * with an opposite entry). `amount` is positive — the type gives the direction.
 */
class CashMovement extends Model
{
    use AppendOnly, BelongsToBranch, HasPublicUuid;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'business_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class)->withTrashed();
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rider_id')->withTrashed();
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /** + into the drawer, − out of it. */
    public function signedAmount(): float
    {
        return $this->type->direction() * (float) $this->amount;
    }
}
