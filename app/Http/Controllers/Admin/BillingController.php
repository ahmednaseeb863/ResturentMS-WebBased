<?php

namespace App\Http\Controllers\Admin;

use App\Actions\RefundPayment;
use App\Actions\SplitBill;
use App\Actions\TakePayment;
use App\Enums\PaymentMethod;
use App\Enums\PrintDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentRequest;
use App\Http\Requests\PosOrderRequest;
use App\Models\BankAccount;
use App\Models\BillSplit;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Support\Activity;
use App\Support\CurrentBranch;
use App\Support\Printing\BillSlip;
use App\Support\Printing\PrintQueue;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Billing of an order (PLAN §4.13): take payment (cash / bank transfer / both), split the
 * bill, print the pre-bill and receipt, refund. Payments go into the cashier's open shift.
 */
class BillingController extends Controller
{
    public function pay(PaymentRequest $request, Order $order, TakePayment $take): RedirectResponse
    {
        $admin = $request->user('admin');
        $shift = Shift::openFor($admin)
            ?? throw ValidationException::withMessages(['payment' => 'Open your shift on a cash counter to take payments.']);

        $split = $request->split();
        $result = $take->handle($order, $request->tenders(), $split, $admin, $shift);
        $order->refresh();

        $message = 'Received '.money($result['paid']).($result['change'] > 0 ? ' — give back '.money($result['change']).' change' : '');
        $message .= match (true) {
            $result['completed'] => ". Order {$order->code()} is complete.",
            $result['settled'] => '. Paid in full — the order completes when the kitchen is done.',
            $split && $split->refresh()->due() <= 0 => ". {$split->label} has paid; ".money($order->due()).' still due.',
            default => '. '.money($order->due()).' still due.',
        };

        // receipt when the bill (or this part of it) is paid
        $print = null;
        $partPaid = $split && $split->due() <= 0;
        if (setting('receipt.print_on_payment') && ($result['settled'] || $partPaid)) {
            [$sentTo, $print] = $this->queue($order, PrintDocument::Receipt, $partPaid && ! $result['settled'] ? $split : null, false);
            $message .= $sentTo ? " Receipt sent to {$sentTo}." : '';
        }

        $redirect = $request->input('return') === 'pos' && ! $order->isOpen() ? to_route('pos.index') : back();

        return $redirect->with('success', $message)->with('print', $print);
    }

    /** Split the bill equally / by items, or remove the split. */
    public function split(Request $request, Order $order, SplitBill $split): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['nullable', Rule::in(['equal', 'items'])],
            'parts' => ['required_if:mode,equal', 'nullable', 'integer', 'min:2', 'max:20'],
            'assignments' => ['required_if:mode,items', 'nullable', 'array', 'max:20'],
            'assignments.*' => ['array', 'max:100'],
            'assignments.*.*.item' => ['required', 'uuid'],
            'assignments.*.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ], ['assignments.required_if' => 'Give the items to the guests.']);

        $mode = $data['mode'] ?? null;
        $assignments = [];
        if ($mode === 'items') {
            $lines = $order->lines()->live()->get()->keyBy('uuid');
            foreach ($data['assignments'] as $part) {
                $assignments[] = array_map(fn (array $row) => [
                    'item' => $lines->get($row['item']) ?? throw ValidationException::withMessages(['split' => 'An item is no longer on the bill — reload and split again.']),
                    'quantity' => (int) $row['quantity'],
                ], $part);
            }
        }

        $split->handle($order, $mode, (int) ($data['parts'] ?? 0), $assignments, $request->user('admin'));

        return back()->with('success', $mode ? 'Bill split into '.$order->splits()->count().' parts.' : 'Split removed — one bill again.');
    }

    /** Print the bill before paying (whole order or one part). */
    public function printBill(Request $request, Order $order): RedirectResponse
    {
        if ($order->isDraft() || ! $order->isOpen()) {
            return back()->with('error', "Order {$order->code()} has no bill to print.");
        }

        $split = $this->splitOf($request, $order);
        [$sentTo, $print] = $this->queue($order, PrintDocument::PreBill, $split, false);
        Activity::log('bill_printed', $order, array_filter(['part' => $split?->label]));

        return back()->with('success', $sentTo ? "Bill sent to {$sentTo}." : 'Printing the bill…')->with('print', $print);
    }

    /** Print the receipt again. */
    public function printReceipt(Request $request, Order $order): RedirectResponse
    {
        if (! $order->payments()->exists()) {
            return back()->with('error', "Order {$order->code()} has no payments yet — print the bill instead.");
        }

        $split = $this->splitOf($request, $order);
        [$sentTo, $print] = $this->queue($order, PrintDocument::Receipt, $split, true);
        Activity::log('receipt_reprinted', $order, array_filter(['part' => $split?->label]));

        return back()->with('success', $sentTo ? "Receipt sent to {$sentTo}." : 'Printing the receipt…')->with('print', $print);
    }

    /** The bill / receipt page for the browser print dialog (`?kind=bill|receipt&split=&auto=1`). */
    public function page(Request $request, Order $order, CurrentBranch $current): View
    {
        abort_if($order->isDraft(), 404);

        $split = $this->splitOf($request, $order);
        $document = $request->query('kind') === 'receipt' ? PrintDocument::Receipt : PrintDocument::PreBill;
        $reprint = $request->boolean('copy');
        $title = ($document === PrintDocument::Receipt ? 'Receipt' : 'Bill').' · '.$order->code().($split ? " · {$split->label}" : '');

        return view('print.bill', [
            'title' => $title,
            'printer' => null,
            'slip' => BillSlip::data($order, $document, $split, $reprint),
            'paper' => (int) setting('receipt.paper_width') ?: 80,
            'auto' => $request->boolean('auto'),
            'doneId' => 'page',
        ]);
    }

    public function refund(Request $request, Order $order, Payment $payment, RefundPayment $refund): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'bank' => ['nullable', 'uuid'],
            'reference' => ['nullable', 'string', 'max:80'],
            'reason' => ['required', 'string', 'max:255'],
            'pin' => ['nullable', 'digits_between:4,6'],
        ], ['reason.required' => 'Say why the money is given back.']);

        $method = PaymentMethod::from($data['method']);
        $bank = $method === PaymentMethod::BankTransfer && ! empty($data['bank'])
            ? BankAccount::query()->active()->availableAt($order->branch_id)->where('uuid', $data['bank'])->first()
            : null;
        if ($method === PaymentMethod::BankTransfer && ! $bank) {
            throw ValidationException::withMessages(['bank' => 'Pick the bank account the refund is sent from.']);
        }

        $admin = $request->user('admin');
        $approver = setting('approvals.pin_refund') ? PosOrderRequest::approve(['orders.payments.refund'], $data['pin'] ?? null) : null;

        $done = $refund->handle($payment, (float) $data['amount'], $method, $bank, $data['reason'], $data['reference'] ?? null, $admin, Shift::openFor($admin), $approver);

        return back()->with('success', 'Refunded '.money($done->amount).' by '.mb_strtolower($method->label()).'.');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function splitOf(Request $request, Order $order): ?BillSplit
    {
        return $request->filled('split') ? $order->splits()->where('uuid', (string) $request->input('split'))->firstOrFail() : null;
    }

    /**
     * Queue the bill / receipt on the counter's receipt printer, or hand the page to the
     * browser (flash `print`) when there is none.
     *
     * @return array{0: ?string, 1: ?array{url: string, title: string}}
     */
    private function queue(Order $order, PrintDocument $document, ?BillSplit $split, bool $reprint): array
    {
        $job = PrintQueue::bill($order, $document, $split, $reprint);

        if ($job) {
            return [$job->printer->name, null];
        }

        return [null, [
            'url' => route('orders.bill', array_filter([
                'order' => $order->uuid,
                'kind' => $document === PrintDocument::Receipt ? 'receipt' : 'bill',
                'split' => $split?->uuid,
                'copy' => $reprint ? 1 : null,
                'auto' => 1,
            ])),
            'title' => ($document === PrintDocument::Receipt ? 'Receipt' : 'Bill').' · '.$order->code(),
        ]];
    }
}
