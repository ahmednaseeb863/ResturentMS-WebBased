<?php

namespace App\Models;

use App\Enums\ShiftStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A cash drawer of the branch with its own receipt printer and (Phase 7) shift. */
class CashCounter extends Model
{
    use BelongsToBranch, HasFactory, HasPublicUuid, LogsActivity, Trashable;

    protected $fillable = ['name', 'receipt_printer_id', 'is_active'];

    protected array $trashParents = ['receiptPrinter'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function receiptPrinter(): BelongsTo
    {
        return $this->belongsTo(Printer::class, 'receipt_printer_id')->withTrashed();
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function openShift(): HasOne
    {
        return $this->hasOne(Shift::class)->where('status', ShiftStatus::Open);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function canBeTrashed(): true|string
    {
        $open = $this->openShift()->first();

        return $open ? "Shift {$open->code()} is open on it — close the shift first." : true;
    }

    /** Opening cash suggested for the next shift: the float the last shift left in the drawer. */
    public function lastFloat(): float
    {
        return (float) ($this->shifts()->where('status', ShiftStatus::Closed)->latest('number')->value('float_left') ?? 0);
    }
}
