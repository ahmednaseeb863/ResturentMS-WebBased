<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\RefundResource;
use App\Models\BankAccount;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\BusinessDate;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Money received and given back in the branch (pos-react Payments): by business date, method, bank account. */
class PaymentController extends Controller
{
    public function index(Request $request, CurrentBranch $current): Response
    {
        $tab = $request->query('tab') === 'refunds' ? 'refunds' : 'payments';
        $method = PaymentMethod::tryFrom((string) $request->query('method'));
        $bank = BankAccount::withTrashed()->where('uuid', (string) $request->query('bank'))->first();
        $today = BusinessDate::for();
        $fresh = $request->query->count() === 0; // first visit: today's business day
        $from = $this->date($request->query('from')) ?? ($fresh ? $today : null);
        $to = $this->date($request->query('to')) ?? ($fresh ? $today : null);
        $search = trim((string) $request->query('search'));

        $filtered = fn (Builder $q) => $q
            ->when($method, fn ($q) => $q->where('method', $method))
            ->when($bank, fn ($q) => $q->where('bank_account_id', $bank->id))
            ->when($from, fn ($q) => $q->whereDate('business_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('business_date', '<=', $to))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$search}%"))
                ->orWhere('reference_no', 'like', "%{$search}%")));

        $sum = fn (string $model, ?PaymentMethod $m = null) => (float) $model::query()->tap($filtered)
            ->when($m, fn ($q) => $q->where('method', $m))->sum('amount');

        $rows = $tab === 'refunds'
            ? RefundResource::collection(Refund::query()->tap($filtered)
                ->with(['order.table', 'order.customer', 'bankAccount', 'refundedBy', 'approvedBy', 'shift', 'payment'])
                ->latest('id')->paginate(20)->withQueryString())
            : PaymentResource::collection(Payment::query()->tap($filtered)
                ->with(['order.table', 'order.customer', 'bankAccount', 'receivedBy', 'shift', 'split'])
                ->latest('id')->paginate(20)->withQueryString());

        $received = $sum(Payment::class);
        $refunded = $sum(Refund::class);

        return Inertia::render('payments/Index', [
            'tab' => $tab,
            'rows' => $rows,
            'filters' => ['method' => $method?->value ?? '', 'bank' => $bank?->uuid ?? '', 'from' => $from ?? '', 'to' => $to ?? '', 'search' => $search],
            'stats' => [
                'cash' => $sum(Payment::class, PaymentMethod::Cash),
                'bank' => $sum(Payment::class, PaymentMethod::BankTransfer),
                'refunded' => $refunded,
                'net' => round($received - $refunded, 2),
                'payments' => Payment::query()->tap($filtered)->count(),
                'refunds' => Refund::query()->tap($filtered)->count(),
            ],
            'methods' => PaymentMethod::options(),
            'banks' => BankAccount::withTrashed()->availableAt($current->id())->orderBy('bank_name')->get()
                ->map(fn (BankAccount $b) => ['value' => $b->uuid, 'label' => $b->trashLabel()])->all(),
            'businessDate' => $today,
        ]);
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
