<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money paid to a supplier (PLAN §4.16), only through PaySupplier: cash out of a shift
 * drawer (a supplier-payment cash movement) or a bank transfer. Append-only.
 */
class SupplierPayment extends Model
{
    use AppendOnly, BelongsToBranch, HasPublicUuid;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'amount' => 'decimal:2', 'method' => PaymentMethod::class];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'paid_by')->withTrashed();
    }
}
