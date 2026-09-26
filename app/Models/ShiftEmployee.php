<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member of staff on duty during a shift (checked in / out). Trashed only when added by mistake. */
class ShiftEmployee extends Model
{
    use HasPublicUuid, Trashable;

    protected $fillable = ['shift_id', 'employee_id', 'checked_in_at', 'checked_out_at'];

    protected bool $logTrashActivity = false;

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime', 'checked_out_at' => 'datetime'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function isOnDuty(): bool
    {
        return $this->checked_out_at === null;
    }
}
