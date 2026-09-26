<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money given back on a payment (append-only), through App\Actions\RefundPayment.
 * Cash refunds come out of the refunder's open shift drawer.
 */
class Refund extends Model
{
    use AppendOnly, BelongsToBranch, HasPublicUuid;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'business_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'refunded_by')->withTrashed();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by')->withTrashed();
    }
}
