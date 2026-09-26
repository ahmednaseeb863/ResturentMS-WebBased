<?php

namespace App\Support\Printing;

use App\Enums\PrintDocument;
use App\Enums\PrintJobStatus;
use App\Models\KitchenTicket;
use App\Models\OrderItem;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Support\LiveUpdates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Queues documents for the branch's printers (PLAN §4.12 / §8). The server only records
 * the job; a browser at the counter / kitchen that prints for that printer claims it.
 *   kot      — a kitchen ticket on its station's printer (auto on send, or reprint)
 *   voidSlip — tells the station an item was voided
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
