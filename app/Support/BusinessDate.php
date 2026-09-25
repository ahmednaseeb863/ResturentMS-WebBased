<?php

namespace App\Support;

use App\Models\Branch;
use Carbon\CarbonImmutable;

/**
 * The trading day a moment belongs to (PLAN §6). Sales at 02:00 still count for the
 * previous day when the business day starts at 05:00 (setting `orders.business_day_cutoff`),
 * in the branch timezone (`general.timezone`). Financial / stock rows store this date.
 */
class BusinessDate
{
    public static function for(Branch|int|null $branch = null, ?CarbonImmutable $at = null): string
    {
        $branch ??= app(CurrentBranch::class)->id();
        $now = ($at ?? CarbonImmutable::now())->setTimezone(setting('general.timezone', $branch));
        $cutoff = (string) setting('orders.business_day_cutoff', $branch);

        return ($now->format('H:i') < $cutoff ? $now->subDay() : $now)->toDateString();
    }
}
