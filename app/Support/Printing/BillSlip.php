<?php

namespace App\Support\Printing;

use App\Enums\OrderType;
use App\Enums\PrintDocument;
use App\Models\BankAccount;
use App\Models\BillSplit;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PrintJob;
use App\Support\Settings\SettingsResolver;
use Carbon\CarbonInterface;

/**
 * The customer's bill (pre-bill, before paying) or receipt (after paying), built once from
 * the Receipt settings and rendered two ways: ESC/POS for QZ Tray (`escPos`) or the
 * print-CSS page for the browser dialog. A part of a split bill prints its own share
 * (and, when split by items, its own items).
 */
class BillSlip
{
    public static function forJob(PrintJob $job): array
    {
        $reference = $job->reference;
        $split = $reference instanceof BillSplit ? $reference : null;
        $order = $split ? $split->order : $reference;

        return static::data($order, $job->document_type, $split, str_ends_with($job->title, '(reprint)'));
    }

    /**
     * @return array{receipt: bool, heading: string, reprint: bool, business: string, branch: ?string, address: ?string,
     *     phone: ?string, ntn: ?string, header: ?string, footer: ?string, logo: ?string, order: string, time: string,
     *     info: list<array{0: string, 1: string}>, lines: list<array>, totals: list<array>, split: ?array,
     *     payments: list<array{0: string, 1: string}>, banks: list<array>}
     */
    public static function data(Order $order, PrintDocument $document, ?BillSplit $split = null, bool $reprint = false): array
    {
        $order->loadMissing('branch', 'table', 'waiter', 'customer', 'delivery', 'createdBy', 'lines.modifiers', 'lines.children', 'payments.bankAccount', 'payments.receivedBy');
        $branch = $order->branch_id;
        $settings = app(SettingsResolver::class);
        $get = fn (string $key) => $settings->get($key, $branch);
        $receipt = $document === PrintDocument::Receipt;
        $money = fn ($amount) => money($amount, $branch);

        $info = [[$order->type->label(), static::time($order->placed_at ?? $order->created_at, $branch)]];
        if ($order->type === OrderType::DineIn && $get('receipt.show_table') && $order->getRelationValue('table')) {
            $info[] = ['Table', $order->getRelationValue('table')->name.($order->guests ? " · {$order->guests} guests" : '')];
        }
        if ($get('receipt.show_waiter') && $order->waiter) {
            $info[] = ['Waiter', $order->waiter->name];
        }
        if ($get('receipt.show_customer') && $order->customer) {
            $info[] = ['Customer', $order->customer->name];
            if ($order->customer->phone) {
                $info[] = ['Phone', $order->customer->phone];
            }
        }
        if ($get('receipt.show_customer') && $order->delivery) {
            $info[] = ['Deliver to', $order->delivery->address];
        }
        $cashier = $receipt ? $order->payments->last()?->receivedBy : $order->createdBy;
        if ($cashier) {
            $info[] = [$receipt ? 'Cashier' : 'Served by', $cashier->name];
        }

        // a part split by items prints only its items
        $only = $split && $split->items ? collect($split->items)->pluck('quantity', 'order_item_id') : null;
        $lines = $order->lines->filter(fn (OrderItem $l) => ! $l->isVoided() && (! $only || $only->has($l->id)))
            ->map(function (OrderItem $l) use ($only) {
                $quantity = $only ? (int) $only[$l->id] : $l->quantity;
                $amount = $l->quantity ? ((float) $l->line_total) / $l->quantity * $quantity : 0;

                return [
                    'quantity' => $quantity,
                    'name' => $l->fullName(),
                    'extras' => [
                        ...$l->modifiers->map(fn ($m) => '+ '.$m->name)->all(),
                        ...$l->children->filter(fn (OrderItem $c) => ! $c->isVoided())->map(fn (OrderItem $c) => "{$c->quantity} x {$c->fullName()}")->all(),
                    ],
                    'discount' => (float) $l->discount_amount > 0 ? '-'.number_format((float) $l->discount_amount / $l->quantity * $quantity, 2) : null,
                    'amount' => number_format($amount, 2),
                ];
            })->values()->all();

        $totals = [['Subtotal', $money($order->items_total), false]];
        if ((float) $order->discount_total > 0) {
            $totals[] = ['Discount', '-'.$money($order->discount_total), false];
        }
        if ((float) $order->service_charge > 0) {
            $totals[] = ['Service '.(float) $order->service_charge_rate.'%', $money($order->service_charge), false];
        }
        if ((float) $order->delivery_fee > 0) {
            $totals[] = ['Delivery fee', $money($order->delivery_fee), false];
        }
        if ((float) $order->tax_total > 0 && $get('receipt.show_tax_line')) {
            $totals[] = [$order->tax_name.' '.(float) $order->tax_rate.'%', $money($order->tax_total), false];
        }
        if ((float) $order->round_off !== 0.0) {
            $totals[] = ['Round off', $money($order->round_off), false];
        }
        $totals[] = ['TOTAL', $money($order->grand_total), true];

        $payments = [];
        $paymentsOf = $split ? $order->payments->where('bill_split_id', $split->id) : $order->payments;
        foreach ($paymentsOf as $payment) {
            /** @var Payment $payment */
            $payments[] = [$payment->methodText(), $money($payment->amount)];
            if ((float) $payment->tendered > 0 && (float) $payment->change_given > 0) {
                $payments[] = ['  Cash received', $money($payment->tendered)];
                $payments[] = ['  Change', $money($payment->change_given)];
            }
            if ((float) $payment->refunded_total > 0) {
                $payments[] = ['  Refunded', '-'.$money($payment->refunded_total)];
            }
        }
        $due = $split ? $split->due() : $order->due();
        if ($payments || $receipt) {
            $payments[] = [$due > 0 ? 'Balance due' : 'Paid in full', $due > 0 ? $money($due) : $money($split ? $split->paid() : $order->paid_total)];
        }

        $banks = $get('receipt.show_bank_accounts')
            ? BankAccount::query()->active()->availableAt($branch)->where('show_on_receipt', true)->orderBy('bank_name')->get()
                ->map(fn (BankAccount $b) => ['bank' => $b->bank_name, 'title' => $b->account_title, 'number' => $b->account_number, 'iban' => $b->iban])->all()
            : [];

        return [
            'receipt' => $receipt,
            'heading' => $receipt ? 'RECEIPT' : 'BILL',
            'reprint' => $reprint,
            'business' => (string) $get('general.business_name'),
            'branch' => $order->branch?->name,
            'address' => $get('general.address'),
            'phone' => $get('general.phone'),
            'ntn' => $get('receipt.show_ntn') ? $get('general.ntn') : null,
            'header' => $get('receipt.header_text'),
            'footer' => $get('receipt.footer_text'),
            'logo' => $get('receipt.show_logo') ? $settings->url('general.logo', $branch) : null,
            'order' => $order->code(),
            'time' => static::time(now(), $branch),
            'info' => $info,
            'lines' => $lines,
            'totals' => $totals,
            'split' => $split ? [
                'label' => $split->label,
                'of' => $order->splits()->count(),
                'amount' => $money($split->amount),
                'by_items' => (bool) $split->items,
            ] : null,
            'payments' => $payments,
            'banks' => $banks,
        ];
    }

    private static function time(CarbonInterface $at, int $branch): string
    {
        $at = $at->copy()->timezone(setting('general.timezone', $branch));

        return $at->format(setting('general.time_format', $branch) === '24h' ? 'd M Y, H:i' : 'd M Y, h:i A');
    }

    /** Raw ESC/POS bytes for a thermal printer. */
    public static function escPos(array $slip, int $paperWidth, int $copies = 1): string
    {
        $bytes = '';

        for ($copy = 0; $copy < $copies; $copy++) {
            $p = new EscPos($paperWidth);
            $p->center()->large()->bold()->text($slip['business'])->large(false)->bold(false);
            foreach (array_filter([$slip['branch'], $slip['address'], $slip['phone'] ? "Tel: {$slip['phone']}" : null, $slip['ntn'] ? "NTN: {$slip['ntn']}" : null, $slip['header']]) as $line) {
                $p->text($line);
            }
            $p->rule();
            $p->bold()->text($slip['heading'].($slip['reprint'] ? ' (COPY)' : ''))->bold(false);
            $p->left()->large()->bold()->text($slip['order'])->large(false)->bold(false);
            foreach ($slip['info'] as [$label, $value]) {
                $p->pair($label, $value);
            }
            $p->rule();

            if ($slip['split']) {
                $p->bold()->text("{$slip['split']['label']} of {$slip['split']['of']}".($slip['split']['by_items'] ? ' - own items' : ''))->bold(false);
            }
            foreach ($slip['lines'] as $line) {
                $p->pair("{$line['quantity']} x {$line['name']}", $line['amount']);
                foreach ($line['extras'] as $extra) {
                    $p->text("   {$extra}", 5);
                }
                if ($line['discount']) {
                    $p->pair('   Discount', $line['discount']);
                }
            }
            $p->rule();

            foreach ($slip['totals'] as [$label, $amount, $strong]) {
                if ($strong) {
                    $p->bold()->large()->pair($label, $amount)->large(false)->bold(false);
                } else {
                    $p->pair($label, $amount);
                }
            }
            if ($slip['split']) {
                $p->rule()->bold()->pair("{$slip['split']['label']} pays", $slip['split']['amount'])->bold(false);
            }
            if ($slip['payments']) {
                $p->rule();
                foreach ($slip['payments'] as [$label, $amount]) {
                    $p->pair($label, $amount);
                }
            }
            if ($slip['banks']) {
                $p->rule()->text('Pay by bank transfer:');
                foreach ($slip['banks'] as $bank) {
                    $p->text("{$bank['bank']} - {$bank['title']}")->text($bank['number']);
                    if ($bank['iban']) {
                        $p->text($bank['iban']);
                    }
                }
            }

            $p->rule()->center()->text("Printed {$slip['time']}");
            if ($slip['footer']) {
                $p->text($slip['footer']);
            }
            $p->feed(3)->cut();

            $bytes .= $p->toString();
        }

        return $bytes;
    }
}
