<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasBranchNumber;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\NeverDeleted;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Money spent by a branch (PLAN §4.18), only through RecordExpense: cash out of a shift's
 * drawer (a paid-out expense cash movement) or a transfer from a bank account. Never
 * deleted — VoidExpense marks it voided (a cash one puts the money back into a drawer).
 */
class Expense extends Model
{
    use BelongsToBranch, HasBranchNumber, HasPublicUuid, NeverDeleted;

    protected const CODE_PREFIX = 'EXP';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'amount' => 'decimal:2',
            'paid_from' => PaymentMethod::class,
            'voided_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id')->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by')->withTrashed();
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'voided_by')->withTrashed();
    }

    /** Not voided — the ones that count. */
    public function scopeKept(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function attachmentUrl(): ?string
    {
        return $this->attachment ? Storage::disk('public')->url($this->attachment) : null;
    }
}
