<?php

namespace App\Models;

use App\Enums\OfferStatus;
use App\Enums\OrderType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasImage;
use App\Models\Concerns\HasOfferDates;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fixed-price bundle (PLAN §4.8): slots of menu items / ready items. Sold only inside
 * its dates, on its days of the week, inside its time window and for its order types.
 * Menu items inside a deal use their recipes; ready items reduce their own stock.
 */
class Deal extends Model
{
    use BelongsToBranch, HasFactory, HasImage, HasOfferDates, HasPublicUuid, LogsActivity, Trashable;

    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    protected $fillable = [
        'name', 'description', 'image', 'price', 'starts_on', 'ends_on', 'days_of_week',
        'start_time', 'end_time', 'available_for', 'is_active', 'sort_order',
    ];

    protected array $trashCascade = ['slots'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'days_of_week' => 'array',
            'available_for' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function slots(): HasMany
    {
        return $this->hasMany(DealSlot::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Can the deal be sold at this moment? Dates and days follow the business date (a
     * Friday-night deal still runs at 01:00 on Saturday), the time window the branch clock.
     */
    public function isAvailableAt(?CarbonImmutable $at = null, ?OrderType $type = null): bool
    {
        $at ??= CarbonImmutable::now();
        $date = BusinessDate::for($this->branch_id, $at);

        if ($this->statusOn($date) !== OfferStatus::Active) {
            return false;
        }
        if ($type && ! in_array($type->value, $this->available_for ?? [], true)) {
            return false;
        }
        if ($this->days_of_week && ! in_array(CarbonImmutable::parse($date)->dayOfWeekIso, $this->days_of_week, true)) {
            return false;
        }

        return $this->inTimeWindow($at->setTimezone(setting('general.timezone', $this->branch_id))->format('H:i'));
    }

    /** 12:00–16:00, or overnight 22:00–02:00 (end not included). */
    public function inTimeWindow(string $time): bool
    {
        if (! $this->start_time || ! $this->end_time) {
            return true;
        }

        $start = substr($this->start_time, 0, 5);
        $end = substr($this->end_time, 0, 5);

        return $start <= $end
            ? $time >= $start && $time < $end
            : $time >= $start || $time < $end;
    }

    /** "Mon–Fri · 12:00–16:00", "Every day · all day" */
    public function scheduleText(): string
    {
        $days = $this->days_of_week ? static::daysText($this->days_of_week) : 'Every day';
        $time = $this->start_time && $this->end_time
            ? substr($this->start_time, 0, 5).'–'.substr($this->end_time, 0, 5)
            : 'all day';

        return "{$days} · {$time}";
    }

    /** [1,2,3,4,5] → "Mon–Fri", [5,6] → "Fri, Sat" */
    public static function daysText(array $days): string
    {
        sort($days);

        if (count($days) === 7) {
            return 'Every day';
        }
        if (count($days) > 2 && end($days) - $days[0] === count($days) - 1) {
            return self::DAYS[$days[0]].'–'.self::DAYS[end($days)];
        }

        return collect($days)->map(fn (int $d) => self::DAYS[$d])->join(', ');
    }

    /** Refuse to restore while an item of the deal is in the trash. */
    public function canBeRestored(): true|string
    {
        $options = DealSlotOption::query()->withTrashed()
            ->whereIn('deal_slot_id', $this->slots()->withTrashed()->pluck('id'))
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('trash_batch', $this->trash_batch))
            // the items are the deal's branch; don't depend on the branch being acted as (Recycle Bin, jobs)
            ->with(['sellable' => fn ($q) => $q->withoutGlobalScope('branch')])
            ->get();

        $trashed = $options->map(fn (DealSlotOption $o) => $o->sellable)
            ->filter(fn (?Model $s) => $s?->isTrashed());

        return $trashed->isEmpty()
            ? true
            : '“'.$trashed->first()->name.'” is in the trash — restore it first.';
    }
}
