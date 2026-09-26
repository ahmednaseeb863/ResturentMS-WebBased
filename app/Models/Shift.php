<?php

namespace App\Models;

use App\Enums\ShiftStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\NeverDeleted;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cashier's session on a cash counter (PLAN §6). Opened with a float, closed with a
 * count; reopened only by a shift manager. Its business_date never changes. Never
 * trashed — money records are closed / reversed, not removed.
 */
class Shift extends Model
{
    use BelongsToBranch, HasFactory, HasPublicUuid, NeverDeleted;

    /** Holders of this route are "shift managers": reopen, approve differences, handle others' shifts. */
    public const MANAGER_ROUTE = 'shifts.reopen';

    protected $guarded = ['id', 'open_counter_id', 'open_cashier_key'];

    protected function casts(): array
    {
        return [
            'status' => ShiftStatus::class,
            'business_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'difference' => 'decimal:2',
            'float_left' => 'decimal:2',
            'handed_over_amount' => 'decimal:2',
            'number' => 'integer',
            'reopen_count' => 'integer',
        ];
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(CashCounter::class, 'cash_counter_id')->withTrashed();
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ShiftType::class, 'shift_type_id')->withTrashed();
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'opened_by')->withTrashed();
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'closed_by')->withTrashed();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by')->withTrashed();
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reopened_by')->withTrashed();
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class)->orderBy('id');
    }

    public function counts(): HasMany
    {
        return $this->hasMany(ShiftCashCount::class)->orderByDesc('denomination');
    }

    public function staff(): HasMany
    {
        return $this->hasMany(ShiftEmployee::class)->orderBy('checked_in_at')->orderBy('id');
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', ShiftStatus::Open);
    }

    public function isOpen(): bool
    {
        return $this->status === ShiftStatus::Open;
    }

    /** "SHF-042" */
    public function code(): string
    {
        return 'SHF-'.str_pad((string) $this->number, 3, '0', STR_PAD_LEFT);
    }

    /** Label in the activity log. */
    public function trashLabel(): string
    {
        return $this->code().($this->counter ? ' · '.$this->counter->name : '');
    }

    /** The admin's open shift in the current branch (the one POS payments go to). */
    public static function openFor(Admin $admin): ?self
    {
        return static::query()->open()->where('opened_by', $admin->id)->with('counter')->first();
    }

    /** Own shift, or a shift manager handling someone else's. */
    public function canBeHandledBy(Admin $admin): bool
    {
        return $this->opened_by === $admin->id || $admin->canRoute(self::MANAGER_ROUTE);
    }

    /** When the shift type says it should end (null without a type). Overnight types end the next day. */
    public function scheduledEnd(): ?CarbonImmutable
    {
        $type = $this->type;
        if (! $type) {
            return null;
        }

        $timezone = setting('general.timezone', $this->branch_id);
        $start = CarbonImmutable::parse($this->business_date->toDateString().' '.$type->startsAt(), $timezone);

        // a type starting before the business-day cutoff (e.g. 02:00) runs on the next calendar day
        if ($type->startsAt() < (string) setting('orders.business_day_cutoff', $this->branch_id)) {
            $start = $start->addDay();
        }

        return $start->addMinutes($type->durationMinutes());
    }

    public function isOverdue(?CarbonImmutable $now = null): bool
    {
        $end = $this->scheduledEnd();

        return $this->isOpen() && $end !== null && ($now ?? CarbonImmutable::now())->greaterThan($end);
    }

    /** Notes & coins counted at open / close, largest first (setting `shifts.denominations`). */
    public static function denominations(Branch|int|null $branch = null): array
    {
        return collect(explode(',', (string) setting('shifts.denominations', $branch)))
            ->map(fn (string $d) => (float) trim($d))
            ->filter(fn (float $d) => $d > 0)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }
}
