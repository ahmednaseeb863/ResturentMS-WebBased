<?php

namespace App\Models\Concerns;

use App\Enums\OfferStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deals and discounts that run between optional dates (`starts_on` / `ends_on`, both
 * inclusive) and can be switched off (`is_active`). Dates are business dates.
 */
trait HasOfferDates
{
    public function statusOn(string $businessDate): OfferStatus
    {
        return match (true) {
            ! $this->is_active => OfferStatus::Inactive,
            $this->starts_on && $businessDate < $this->starts_on->toDateString() => OfferStatus::Scheduled,
            $this->ends_on && $businessDate > $this->ends_on->toDateString() => OfferStatus::Expired,
            default => OfferStatus::Active,
        };
    }

    /** Active and inside its dates on this business date. */
    public function scopeRunningOn(Builder $query, string $businessDate): void
    {
        $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $businessDate))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $businessDate));
    }

    /** List filter by status on this business date. */
    public function scopeWithStatus(Builder $query, string $status, string $businessDate): void
    {
        match ($status) {
            'active' => $query->runningOn($businessDate),
            'scheduled' => $query->where('is_active', true)->whereDate('starts_on', '>', $businessDate),
            'expired' => $query->where('is_active', true)->whereDate('ends_on', '<', $businessDate),
            'inactive' => $query->where('is_active', false),
            default => null,
        };
    }

    /** Status counts for the stat cards. */
    public static function statusCounts(string $businessDate): array
    {
        return collect(['active', 'scheduled', 'expired', 'inactive'])
            ->mapWithKeys(fn ($s) => [$s => static::query()->withStatus($s, $businessDate)->count()])
            ->all();
    }
}
