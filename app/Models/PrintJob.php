<?php

namespace App\Models;

use App\Enums\PrintDocument;
use App\Enums\PrintJobStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\NeverDeleted;
use App\Models\Concerns\TrashScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A document waiting for a browser in the branch to print it on `printer` (PLAN §8).
 * Queued by App\Support\Printing\PrintQueue; a device claims it, prints it (QZ Tray or
 * the browser dialog) and reports back. Never deleted — failed jobs are retried.
 */
class PrintJob extends Model
{
    use BelongsToBranch, HasPublicUuid, NeverDeleted;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'document_type' => PrintDocument::class,
            'status' => PrintJobStatus::class,
            'copies' => 'integer',
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'printed_at' => 'datetime',
        ];
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class)->withTrashed();
    }

    /** What is printed: kitchen ticket (KOT), order item (void slip), order or bill split (bill / receipt). */
    public function reference(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes([TrashScope::class]);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by')->withTrashed();
    }

    public function printedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'printed_by')->withTrashed();
    }

    public function scopeStatus(Builder $query, PrintJobStatus $status): void
    {
        $query->where('status', $status);
    }
}
