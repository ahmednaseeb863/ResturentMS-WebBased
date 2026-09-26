<?php

namespace App\Actions;

use App\Enums\PaymentStatus;
use App\Enums\StockMovementType;
use App\Models\Admin;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\Unit;
use App\Support\Activity;
use App\Support\BusinessDate;
use App\Support\CurrentBranch;
use App\Support\Qty;
use App\Support\StockLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Receives a supplier invoice into stock (PLAN §4.16): one line per raw material / ready
 * item in any unit it is bought in, at the invoice cost — each line is a purchase in the
 * stock ledger and updates the item's average cost. Invoice discount and tax change the
 * bill only. Payment is separate (PaySupplier).
 */
class ReceivePurchase
{
    public function __construct(private StockLedger $stock) {}

    /**
     * @param  list<array{item: Model, unit: Unit, quantity: float, unit_cost: ?float, stock_quantity: float}>  $lines
     * @param  array{invoice_no: ?string, invoice_date: ?string, discount: float, tax: float, notes: ?string}  $data
     */
    public function handle(Supplier $supplier, array $lines, array $data, Admin $admin): Purchase
    {
        return DB::transaction(function () use ($supplier, $lines, $data, $admin) {
            $branchId = app(CurrentBranch::class)->id();

            $purchase = Purchase::create([
                'branch_id' => $branchId,
                'number' => Purchase::nextNumber($branchId),
                'supplier_id' => $supplier->id,
                'invoice_no' => $data['invoice_no'],
                'invoice_date' => $data['invoice_date'],
                'business_date' => BusinessDate::for($branchId),
                'discount' => round($data['discount'], 2),
                'tax' => round($data['tax'], 2),
                'payment_status' => PaymentStatus::Unpaid,
                'notes' => $data['notes'],
                'received_by' => $admin->id,
            ]);

            $note = "{$purchase->code()} · {$supplier->name}".($data['invoice_no'] ? " · inv {$data['invoice_no']}" : '');
            $subtotal = 0.0;
            $summary = [];

            foreach ($lines as $line) {
                $item = $line['item'];
                $total = round($line['quantity'] * (float) $line['unit_cost'], 2);
                $subtotal += $total;

                $movement = $this->stock->record(
                    $item, StockMovementType::Purchase, $line['stock_quantity'],
                    unitCost: $line['stock_quantity'] > 0 ? $total / $line['stock_quantity'] : null,
                    note: $note, reference: $purchase,
                );

                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'stockable_type' => $item->getMorphClass(),
                    'stockable_id' => $item->getKey(),
                    'item_name' => $item->name,
                    'quantity' => $line['quantity'],
                    'unit_id' => $line['unit']->id,
                    'stock_quantity' => $line['stock_quantity'],
                    'unit_cost' => round((float) $line['unit_cost'], 4),
                    'line_total' => $total,
                    'stock_movement_id' => $movement->id,
                ]);
                $summary[] = Qty::format($line['quantity'])." {$line['unit']->short_name} {$item->name}";
            }

            $subtotal = round($subtotal, 2);
            $purchase->forceFill([
                'subtotal' => $subtotal,
                'total' => max(0, round($subtotal - (float) $purchase->discount + (float) $purchase->tax, 2)),
            ]);
            $purchase->syncPaymentStatus();
            $purchase->save();

            Activity::log('purchase_received', $purchase, array_filter([
                'supplier' => $supplier->name,
                'invoice' => $data['invoice_no'],
                'total' => money($purchase->total, $branchId),
                'items' => $summary,
            ]));

            return $purchase;
        });
    }
}
