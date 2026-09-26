<?php

namespace App\Reports;

use App\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a report covers: a business-date range and one branch or several ("All branches" —
 * only the branches the admin may work in). Reports always filter by `branchIds` themselves
 * (they query tables directly, without the current-branch scope).
 */
final class ReportFilters
{
    /** @param  Collection<int, Branch>  $branches */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly Collection $branches,
    ) {}

    /** @return list<int> */
    public function branchIds(): array
    {
        return $this->branches->pluck('id')->all();
    }

    public function branchLabel(): string
    {
        return $this->branches->count() === 1 ? $this->branches->first()->name : 'All branches ('.$this->branches->count().')';
    }

    public function isMultiBranch(): bool
    {
        return $this->branches->count() > 1;
    }

    /** Branch the settings (timezone, currency) are read from. */
    public function mainBranchId(): int
    {
        return $this->branches->first()->id;
    }

    /** UTC offset of the branch clock, e.g. "+05:00" (to group timestamps by local hour / day). */
    public function utcOffset(): string
    {
        return CarbonImmutable::now(setting('general.timezone', $this->mainBranchId()))->format('P');
    }

    public function periodLabel(): string
    {
        $from = CarbonImmutable::parse($this->from);
        $to = CarbonImmutable::parse($this->to);

        return $this->from === $this->to ? $from->format('j M Y') : $from->format('j M Y').' – '.$to->format('j M Y');
    }

    /** @return list<string> every date in the range */
    public function days(): array
    {
        $days = [];
        for ($d = CarbonImmutable::parse($this->from); $d->toDateString() <= $this->to; $d = $d->addDay()) {
            $days[] = $d->toDateString();
        }

        return $days;
    }
}
