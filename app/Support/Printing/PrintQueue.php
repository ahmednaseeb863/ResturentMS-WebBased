<?php

namespace App\Support\Printing;

use App\Enums\PrintDocument;
use App\Enums\PrintJobStatus;
use App\Models\Admin;
use App\Models\BillSplit;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Shift;
use App\Support\LiveUpdates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Queues documents for the branch's printers (PLAN §4.12 / §8). The server only records
 * the job; a browser at the counter / kitchen that prints for that printer claims it.
 *   kot      — a kitchen ticket on its station's printer (auto on send, or reprint)
 *   voidSlip — tells the station an item was voided
 *   bill     — pre-bill / receipt on the receipt printer of the cashier's counter; without
 *              one the screen prints the bill page itself (browser dialog)
 *   billRequest — pre-bill the waiter asked for, on an open counter's receipt printer
 */
class PrintQueue
{
    /** Tickets just sent: print them when "auto-print KOT" is on. */
    public static function sent(iterable $tickets): void
    {
        if (! setting('printing.kot') || ! setting('printing.auto_print_kot')) {
            return;
        }

        foreach ($tickets as $ticket) {
            static::kot($ticket);
        }
    }

    public static function kot(KitchenTicket $ticket, bool $reprint = false): ?PrintJob
    {
        $printer = static::printerOf($ticket);
        if (! $printer || (! $reprint && ! setting('printing.kot'))) {
            return null;
        }

        $ticket->loadMissing('order');

        return static::queue($printer, PrintDocument::Kot, $ticket,
            $ticket->code().' · '.$ticket->order->code().($reprint ? ' (reprint)' : ''),
            $reprint ? 1 : (int) setting('printing.kot_copies'),
        );
    }

    public static function voidSlip(OrderItem $item): ?PrintJob
    {
        $ticket = $item->ticket;
        $printer = $ticket ? static::printerOf($ticket) : null;
        if (! $printer || ! setting('printing.void_slips')) {
            return null;
        }

        return static::queue($printer, PrintDocument::Void, $item, "VOID {$item->quantity} × {$item->fullName()} · {$ticket->code()}", 1);
    }

    /**
     * A pre-bill or receipt of the order (or one part of a split bill). Null when there is no
     * receipt printer — the caller then prints the page in the browser.
     */
    public static function bill(Order $order, PrintDocument $document, ?BillSplit $split = null, bool $reprint = false, ?Admin $admin = null): ?PrintJob
    {
        $printer = static::receiptPrinter($order, $admin);
        if (! $printer) {
            return null;
        }

        $title = ($document === PrintDocument::Receipt ? 'Receipt' : 'Bill').' · '.$order->code().($split ? " · {$split->label}" : '').($reprint ? ' (reprint)' : '');
        $copies = $document === PrintDocument::Receipt && ! $reprint ? (int) setting('receipt.copies', $order->branch_id) : 1;

        return static::queue($printer, $document, $split ?? $order, $title, $copies);
    }

    /** The waiter asked for the bill: the pre-bill on the receipt printer of an open counter (first opened first). */
    public static function billRequest(Order $order): ?PrintJob
    {
        $printer = Shift::query()->open()->with('counter.receiptPrinter')->oldest('opened_at')->get()
            ->map(fn (Shift $shift) => $shift->counter?->receiptPrinter)
            ->first(fn (?Printer $p) => $p && ! $p->isTrashed() && $p->is_active);

        return $printer ? static::queue($printer, PrintDocument::PreBill, $order, "Bill · {$order->code()} · {$order->label()} (waiter)", 1) : null;
    }

    /** The receipt printer of the admin's counter, else of the counter the order was paid at. */
    public static function receiptPrinter(Order $order, ?Admin $admin = null): ?Printer
    {
        $admin ??= Auth::guard('admin')->user();
        $shift = ($admin ? Shift::openFor($admin) : null) ?? $order->shift;

        $printer = $shift?->counter?->receiptPrinter;

        return $printer && ! $printer->isTrashed() && $printer->is_active ? $printer : null;
    }

    /** Print a printed / failed job again (a new job, so the history stays). */
    public static function again(PrintJob $job): PrintJob
    {
        $title = str_ends_with($job->title, '(reprint)') ? $job->title : "{$job->title} (reprint)";

        return static::queue($job->printer, $job->document_type, $job->reference, $title, 1);
    }

    private static function printerOf(KitchenTicket $ticket): ?Printer
    {
        $printer = $ticket->station?->printer;

        return $printer && ! $printer->isTrashed() && $printer->is_active ? $printer : null;
    }

    private static function queue(Printer $printer, PrintDocument $document, Model $reference, string $title, int $copies): PrintJob
    {
        $job = PrintJob::create([
            'branch_id' => $printer->branch_id,
            'printer_id' => $printer->id,
            'document_type' => $document,
            'reference_type' => $reference->getMorphClass(),
            'reference_id' => $reference->getKey(),
            'title' => mb_substr($title, 0, 120),
            'copies' => max(1, min(5, $copies)),
            'status' => PrintJobStatus::Pending,
            'created_by' => Auth::guard('admin')->id(),
        ]);

        static::announce($job);

        return $job;
    }

    /** Tell the screens printing for this printer that a job waits. */
    public static function announce(PrintJob $job): void
    {
        LiveUpdates::bump('printers', $job->branch_id);
    }
}
