<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\NeverDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Money received for an order (PLAN §4.13), taken only through App\Actions\TakePayment.
 * Cash goes into the drawer of the shift that took it; a transfer into a bank account.
 * Never deleted — refunds (RefundPayment) give money back and add to `refunded_total`.
 */
class Payment extends Model
{
    use BelongsToBranch, HasPublicUuid, NeverDeleted;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'business_date' => 'date',
            'amount' => 'decimal:2',
            'tendered' => 'decimal:2',
            'change_given' => 'decimal:2',
            'refunded_total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function split(): BelongsTo
    {
        return $this->belongsTo(BillSplit::class, 'bill_split_id')->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'received_by')->withTrashed();
    }

    /** Cash a rider collected on delivery (no shift until the rider settles it). */
    public function collectedByRider(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'collected_by_rider_id')->withTrashed();
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('id');
    }

    /** What can still be given back. */
    public function refundable(): float
    {
        return round((float) $this->amount - (float) $this->refunded_total, 2);
    }

    public function proofUrl(): ?string
    {
        return $this->proof_image ? Storage::disk('public')->url($this->proof_image) : null;
    }

    /** "Cash", "Bank transfer · HBL". */
    public function methodText(): string
    {
        return $this->method->label().($this->bankAccount ? ' · '.$this->bankAccount->bank_name : '');
    }
}
