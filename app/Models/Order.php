<?php

namespace App\Models;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\NeverDeleted;
use App\Support\LiveUpdates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;

/**
 * A sale (PLAN §5). Created on the POS or the waiter app; a held order is a draft
 * whose cart waits in `held_items`. Sending turns cart lines into order items, kitchen
 * tickets and ready-item stock movements. Never trashed — lines are voided, orders
 * cancelled. Totals come only from App\Support\OrderPricing.
 */
class Order extends Model
{
    use BelongsToBranch, HasFactory, HasPublicUuid, NeverDeleted;

    protected $guarded = ['id', 'open_table_id'];

    protected static function booted(): void
    {
        // table screens (waiter app) reload when an order changes
        static::saved(fn (self $order) => LiveUpdates::bump('floor', $order->branch_id));
    }

    protected function casts(): array
    {
        return [
            'type' => OrderType::class,
            'source' => OrderSource::class,
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'business_date' => 'date',
            'number' => 'integer',
            'guests' => 'integer',
            'items_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'net_total' => 'decimal:2',
            'service_charge_rate' => 'decimal:2',
            'service_charge_removed' => 'boolean',
            'service_charge' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'round_off' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'refunded_total' => 'decimal:2',
            'held_items' => 'array',
            'placed_at' => 'datetime',
            'bill_requested_at' => 'datetime',
            'paid_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'user_id')->withTrashed();
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'table_id')->withTrashed();
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'waiter_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by')->withTrashed();
    }

    public function billRequestedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'bill_requested_by')->withTrashed();
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'cancelled_by')->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** Every sent line, deal picks included. */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** Top-level lines (a deal once, not its picks). */
    public function lines(): HasMany
    {
        return $this->items()->whereNull('parent_order_item_id');
    }

    /** Live discounts (order and line); replaced ones are in the trash. */
    public function discounts(): HasMany
    {
        return $this->hasMany(OrderDiscount::class);
    }

    public function orderDiscount(): HasOne
    {
        return $this->hasOne(OrderDiscount::class)->whereNull('order_item_id')->latestOfMany();
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(KitchenTicket::class)->orderBy('id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('id');
    }

    /** Live parts of a split bill (replaced ones are in the trash). */
    public function splits(): HasMany
    {
        return $this->hasMany(BillSplit::class)->orderBy('number');
    }

    /** Still to pay: the bill minus the money kept (payments − refunds). */
    public function due(): float
    {
        return max(0, round((float) $this->grand_total - (float) $this->paid_total, 2));
    }

    /** Payment status from the money kept and refunded (call after paid / refunded / total changes). */
    public function syncPaymentStatus(): void
    {
        $kept = (float) $this->paid_total;
        $refunded = (float) $this->refunded_total;

        $this->payment_status = match (true) {
            $kept <= 0 && $refunded > 0 => PaymentStatus::Refunded,
            $kept <= 0 => PaymentStatus::Unpaid,
            $refunded > 0 && ! $this->isOpen() => PaymentStatus::PartRefunded,
            $this->due() <= 0 => PaymentStatus::Paid,
            default => PaymentStatus::Partial,
        };
    }

    /** The waiter asked for the bill and it is still to pay. */
    public function billRequested(): bool
    {
        return $this->bill_requested_at !== null && $this->isOpen() && ! $this->isDraft() && $this->due() > 0;
    }

    /** Placed, still open and not fully paid. */
    public function canTakePayment(): bool
    {
        return $this->isOpen() && ! $this->isDraft() && $this->due() > 0;
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', OrderStatus::open());
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isDraft(): bool
    {
        return $this->status === OrderStatus::Draft;
    }

    /** "#012", "GUL-012"; held orders have no number yet. */
    public function code(): string
    {
        return match (true) {
            $this->order_number === null => 'Held order',
            ctype_digit($this->order_number[0]) => '#'.$this->order_number,
            default => $this->order_number,
        };
    }

    public function trashLabel(): string
    {
        return $this->code();
    }

    /** Move to a status and write the history line (the first line has no "from"). */
    public function moveTo(OrderStatus $status, ?string $note = null): void
    {
        $from = $this->histories()->exists() ? $this->status : null;
        $this->status = $status;
        $this->save();

        $this->histories()->create([
            'from_status' => $from?->value,
            'to_status' => $status->value,
            'admin_id' => Auth::guard('admin')->id(),
            'note' => $note,
        ]);
    }

    /** "Table 5", "Ali · 0300…", "Takeaway" — how the order is recognised on the POS. */
    public function label(): string
    {
        return match ($this->type) {
            // getRelationValue: inside the model `$this->table` is Eloquent's table-name property
            OrderType::DineIn => $this->getRelationValue('table')?->name ?? 'Dine-in',
            default => $this->customer?->name ?? $this->type->label(),
        };
    }
}
