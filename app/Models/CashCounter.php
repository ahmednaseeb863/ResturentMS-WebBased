<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
