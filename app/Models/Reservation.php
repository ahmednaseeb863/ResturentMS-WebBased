<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasBranchNumber;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\NeverDeleted;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A table booking of a branch (PLAN §4.17), changed only through SaveReservation /
 * ChangeReservationStatus. `reserved_at` is the branch's local wall-clock time. Never
 * deleted — cancelled or marked no-show instead.
 */
class Reservation extends Model
{
    use BelongsToBranch, HasBranchNumber, HasPublicUuid, NeverDeleted;

    protected const CODE_PREFIX = 'RSV';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'reserved_at' => 'immutable_datetime',
            'party_size' => 'integer',
            'duration_minutes' => 'integer',
            'confirmed_at' => 'datetime',
            'seated_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'user_id')->withTrashed();
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'table_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by')->withTrashed();
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'closed_by')->withTrashed();
    }

    public function scopeUpcoming(Builder $query): void
    {
        $query->whereIn('status', ReservationStatus::upcoming());
    }

    public function endsAt(): CarbonImmutable
    {
        return $this->reserved_at->addMinutes($this->duration_minutes);
    }

    /** Now in the branch's timezone, as a naive local time (the same clock as `reserved_at`). */
    public static function localNow(int $branchId): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now()->setTimezone(setting('general.timezone', $branchId))->format('Y-m-d H:i:s'));
    }
}
