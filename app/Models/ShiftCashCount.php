<?php

namespace App\Models;

use App\Enums\CashCountType;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a drawer count: 12 × Rs 1000. Closing lines are trashed when the shift is reopened. */
class ShiftCashCount extends Model
{
    use Trashable;

    protected $fillable = ['shift_id', 'type', 'denomination', 'quantity', 'amount'];

    protected bool $logTrashActivity = false;

    protected function casts(): array
    {
        return [
            'type' => CashCountType::class,
            'denomination' => 'decimal:2',
            'quantity' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
