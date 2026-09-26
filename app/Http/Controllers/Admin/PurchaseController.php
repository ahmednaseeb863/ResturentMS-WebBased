<?php

namespace App\Http\Controllers\Admin;

use App\Actions\PaySupplier;
use App\Actions\ReceivePurchase;
use App\Actions\ReturnPurchase;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseRequest;
use App\Http\Requests\SupplierPaymentRequest;
use App\Http\Resources\PurchaseResource;
use App\Models\Purchase;
use App\Models\Shift;
use App\Models\Supplier;
use App\Support\MenuOptions;
use App\Support\PosMenu;
use App\Support\StockOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Purchases of the current branch (PLAN §4.16): receive a supplier invoice into stock
 * (optionally paying it now), the list, the detail with payments and returns.
 */
class PurchaseController extends Controller
{
    public function index(Request $request): Response
    {
        $supplier = $request->filled('supplier') ? Supplier::query()->withTrashed()->where('uuid', $request->query('supplier'))->first() : null;
        $status = PaymentStatus::tryFrom((string) $request->query('status'));
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));
        $search = trim((string) $request->query('search'));

        $filtered = fn ($q) => $q
            ->when($supplier, fn ($q) => $q->where('supplier_id', $supplier->id))
            ->when($from, fn ($q) => $q->whereDate('business_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('business_date', '<=', $to))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('invoice_no', 'like', "%{$search}%")
                ->orWhere('number', ltrim(preg_replace('/\D/', '', $search), '0') ?: -1)
                ->orWhereHas('supplier', fn ($s) => $s->withTrashed()->where('name', 'like', "%{$search}%"))));

        $purchases = Purchase::query()->tap($filtered)
            ->when($status, fn ($q) => $q->where('payment_status', $status))
            ->with('supplier')->withCount('items')
            ->latest('id')->paginate(20)->withQueryString();

        $totals = Purchase::query()->tap($filtered)->toBase()
            ->selectRaw('count(*) as n, coalesce(sum(total - returned_total), 0) as billed, coalesce(sum(greatest(total - returned_total - paid_total, 0)), 0) as due')
            ->first();

        return Inertia::render('purchases/Index', [
            'purchases' => PurchaseResource::collection($purchases),
            'filters' => ['supplier' => $supplier?->uuid ?? '', 'status' => $status?->value ?? '', 'from' => $from ?? '', 'to' => $to ?? '', 'search' => $search],
            'stats' => ['count' => (int) $totals->n, 'billed' => (float) $totals->billed, 'due' => (float) $totals->due],
            'suppliers' => Supplier::query()->orderBy('name')->get()->map(fn (Supplier $s) => ['value' => $s->uuid, 'label' => $s->name])->all(),
            'statuses' => collect([PaymentStatus::Unpaid, PaymentStatus::Partial, PaymentStatus::Paid])->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()])->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('purchases/Create', [
            'suppliers' => Supplier::query()->active()->orderBy('name')->get()->map(fn (Supplier $s) => ['value' => $s->uuid, 'label' => $s->name])->all(),
            'supplier' => (string) $request->query('supplier', ''),
            'items' => StockOptions::items(),
            'units' => MenuOptions::units(),
            'bankAccounts' => PosMenu::bankAccounts(),
            'myShift' => ($shift = Shift::openFor($request->user('admin'))) ? ['id' => $shift->uuid, 'code' => $shift->code()] : null,
        ]);
    }

    public function store(PurchaseRequest $request, ReceivePurchase $receive, PaySupplier $pay): RedirectResponse
    {
        $admin = $request->user('admin');

        $purchase = DB::transaction(function () use ($request, $receive, $pay, $admin) {
            $purchase = $receive->handle($request->supplier(), $request->stockLines(), $request->invoiceData(), $admin);

            if ($request->paidNow() > 0) {
                $pay->handle(
                    $request->supplier(), $purchase, $request->paidNow(),
                    SupplierPaymentRequest::methodOf($request, 'pay.'),
                    Shift::openFor($admin),
                    SupplierPaymentRequest::bankOf($request, 'pay.'),
                    $request->input('pay.reference'), $request->input('pay.notes'), $admin,
                );
            }

            return $purchase->refresh();
        });

        return to_route('purchases.show', $purchase)->with('success', "{$purchase->code()} received — ".money($purchase->total).($purchase->due() > 0 ? ', '.money($purchase->due()).' to pay.' : ', paid.'));
    }

    public function show(Request $request, Purchase $purchase): Response
    {
        $purchase->load([
            'supplier', 'receivedBy', 'items.unit', 'items.stockable.stockUnit',
            'returns.items', 'returns.admin', 'payments.bankAccount', 'payments.paidBy', 'payments.shift',
        ]);

        return Inertia::render('purchases/Show', [
            'purchase' => (new PurchaseResource($purchase))->resolve(),
            'bankAccounts' => PosMenu::bankAccounts(),
            'myShift' => ($shift = Shift::openFor($request->user('admin'))) ? ['id' => $shift->uuid, 'code' => $shift->code()] : null,
        ]);
    }

    /** Send goods back (`lines` = purchase line uuid => quantity in the line's unit). */
    public function returnItems(Request $request, Purchase $purchase, ReturnPurchase $return): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'max:200'],
            'lines.*' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'reason' => ['required', 'string', 'max:255'],
        ], ['reason.required' => 'Say why the goods go back.', 'lines.required' => 'Enter what goes back.']);

        $done = $return->handle($purchase, array_map('floatval', array_filter($data['lines'], fn ($q) => $q !== null)), $data['reason'], $request->user('admin'));

        return back()->with('success', "{$done->code()} — ".money($done->total).' sent back to '.$purchase->supplier->name.'.');
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
