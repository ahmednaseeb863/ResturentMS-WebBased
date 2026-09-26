<?php

namespace App\Http\Controllers\Admin;

use App\Actions\PaySupplier;
use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierPaymentRequest;
use App\Http\Requests\SupplierRequest;
use App\Http\Resources\PurchaseResource;
use App\Http\Resources\SupplierPaymentResource;
use App\Http\Resources\SupplierResource;
use App\Models\Shift;
use App\Models\Supplier;
use App\Support\CurrentBranch;
use App\Support\PosMenu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Suppliers (PLAN §4.16) — shared by every branch; balances, purchases and payments are
 * the current branch's. Pay a supplier from its page (cash from my shift or a bank account).
 */
class SupplierController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'suppliers.restore');
        $search = trim((string) $request->query('search'));
        $branchId = app(CurrentBranch::class)->id();

        $suppliers = $this->applyTab(Supplier::query(), $tab)
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('contact_person', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->withCount(['purchases' => fn ($q) => $q->where('branch_id', $branchId)])
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $suppliers->getCollection()->each(fn (Supplier $s) => $s->balance = $s->balanceIn($branchId));

        return Inertia::render('suppliers/Index', [
            'suppliers' => SupplierResource::collection($suppliers),
            'filters' => ['tab' => $tab, 'search' => $search],
            'counts' => $this->tabCounts(Supplier::class),
        ]);
    }

    public function show(Request $request, Supplier $supplier): Response
    {
        $branchId = app(CurrentBranch::class)->id();
        $supplier->balance = $supplier->balanceIn($branchId);

        return Inertia::render('suppliers/Show', [
            'supplier' => (new SupplierResource($supplier))->resolve(),
            'purchases' => PurchaseResource::collection($supplier->purchases()->with('supplier')->latest('id')->limit(100)->get())->resolve(),
            'payments' => SupplierPaymentResource::collection(
                $supplier->payments()->with(['bankAccount', 'paidBy', 'shift', 'purchase'])->latest('id')->limit(100)->get()
            )->resolve(),
            'bankAccounts' => PosMenu::bankAccounts(),
            'myShift' => ($shift = Shift::openFor($request->user('admin'))) ? ['id' => $shift->uuid, 'code' => $shift->code()] : null,
        ]);
    }

    public function store(SupplierRequest $request): RedirectResponse
    {
        $supplier = Supplier::create($request->supplierData());

        return back()->with('success', "Supplier “{$supplier->name}” added.");
    }

    public function update(SupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($request->supplierData());

        return back()->with('success', "Supplier “{$supplier->name}” saved.");
    }

    public function destroy(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->trash($this->trashReason($request));

        return to_route('suppliers.index')->with('success', "Supplier “{$supplier->name}” moved to trash.");
    }

    public function restore(Supplier $supplier): RedirectResponse
    {
        $supplier->restoreFromTrash();

        return back()->with('success', "Supplier “{$supplier->name}” restored.");
    }

    /** Pay the supplier — one purchase or on account. */
    public function pay(SupplierPaymentRequest $request, Supplier $supplier, PaySupplier $pay): RedirectResponse
    {
        $payment = $pay->handle(
            $supplier,
            $request->purchase(),
            (float) $request->validated('amount'),
            SupplierPaymentRequest::methodOf($request),
            Shift::openFor($request->user('admin')),
            SupplierPaymentRequest::bankOf($request),
            $request->validated('reference'),
            $request->validated('notes'),
            $request->user('admin'),
        );

        return back()->with('success', money($payment->amount)." paid to {$supplier->name}.");
    }
}
