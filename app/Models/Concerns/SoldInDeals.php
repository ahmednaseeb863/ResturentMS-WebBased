<?php

namespace App\Models\Concerns;

use App\Models\DealSlotOption;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Menu items and ready items can be options of deal slots; they can't be trashed while they are. */
trait SoldInDeals
{
    public function dealOptions(): MorphMany
    {
        return $this->morphMany(DealSlotOption::class, 'sellable');
    }

    /** @return list<string> names of the (live) deals offering this item */
    public function dealNames(): array
    {
        return $this->dealOptions()->with('slot.deal')->get()
            ->map(fn (DealSlotOption $o) => $o->slot?->deal?->name)
            ->filter()->unique()->sort()->values()->all();
    }

    protected function dealUsageReason(): ?string
    {
        return static::dealListReason($this->dealNames());
    }

    /** "Part of the deal “Zinger Meal” — remove it there first." */
    public static function dealListReason(array $deals): ?string
    {
        if (! $deals) {
            return null;
        }

        $list = collect($deals)->take(3)->map(fn ($n) => "“{$n}”")->join(', ');
        $more = count($deals) > 3 ? ' and '.(count($deals) - 3).' more' : '';

        return "Part of the deal {$list}{$more} — remove it there first.";
    }
}
