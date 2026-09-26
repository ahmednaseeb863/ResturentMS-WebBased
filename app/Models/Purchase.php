<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasBranchNumber;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\NeverDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier invoice received into stock (PLAN §4.16), only through ReceivePurchase.
 * Never deleted: goods sent back are a PurchaseReturn, money a SupplierPayment.
 * Due = total − returned − paid.
 */
class Purchase extends Model
{
    use BelongsToBranch, HasBranchNumber, HasPublicUuid, NeverDeleted;

    protected const CODE_PREFIX = 'PUR';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'business_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'returned_total' => 'decimal:2',
            'payment_status' => PaymentStatus::class,
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class)->orderBy('id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class)->orderBy('id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'received_by')->withTrashed();
    }

    public function due(): float
    {
        return max(0, round((float) $this->total - (float) $this->returned_total - (float) $this->paid_total, 2));
    }

    public function syncPaymentStatus(): void
    {
        $this->payment_status = match (true) {
            $this->due() <= 0 => PaymentStatus::Paid,
            (float) $this->paid_total > 0 => PaymentStatus::Partial,
            default => PaymentStatus::Unpaid,
        };
    }
}
