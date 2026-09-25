<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Shift template of a branch, e.g. Night 19:00–04:00. End at or before start = overnight. */
class ShiftType extends Model
{
    use BelongsToBranch, HasFactory, HasPublicUuid, LogsActivity, Trashable;

    protected $fillable = ['name', 'start_time', 'end_time', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** "HH:MM" (the TIME column comes back as HH:MM:SS). */
    public function startsAt(): string
    {
        return substr((string) $this->start_time, 0, 5);
    }

    public function endsAt(): string
    {
        return substr((string) $this->end_time, 0, 5);
    }

    public function isOvernight(): bool
    {
        return $this->endsAt() <= $this->startsAt();
    }

    /** Length in minutes, across midnight when overnight. */
    public function durationMinutes(): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $this->startsAt()));
        [$eh, $em] = array_map('intval', explode(':', $this->endsAt()));
        $minutes = ($eh * 60 + $em) - ($sh * 60 + $sm);

        return $minutes <= 0 ? $minutes + 1440 : $minutes;
    }
}
