<?php

namespace App\Support\Printing;

use App\Enums\OrderType;
use App\Enums\PrintDocument;
use App\Models\KitchenTicket;
use App\Models\OrderItem;
use App\Models\PrintJob;
use Carbon\CarbonInterface;

/**
 * What a kitchen ticket (KOT) or void slip says, built once and rendered two ways:
 * ESC/POS bytes for QZ Tray (`escPos`) or the print-CSS page for the browser dialog.
 */
class KitchenSlip
{
    /**
     * @return array{heading: string, void: bool, reprint: bool, station: ?string, order: string, type: string,
     *     table: ?string, waiter: ?string, guests: ?int, customer: ?string, time: string,
     *     lines: list<array{quantity: int, name: string, extras: list<string>, deal: ?string, note: ?string, voided: bool}>,
     *     reason: ?string, by: ?string, notes: ?string}
     */
    public static function data(PrintJob $job): array
    {
        return $job->document_type === PrintDocument::Void
            ? static::voidData($job->reference, $job)
            : static::kotData($job->reference, $job);
    }

    private static function kotData(KitchenTicket $ticket, PrintJob $job): array
    {
        $ticket->loadMissing('station', 'order.table', 'order.waiter', 'order.customer', 'items.modifiers', 'items.parent');
        $order = $ticket->order;

        return [
            ...static::header($ticket, $job),
            'heading' => $ticket->code(),
            'time' => static::time($ticket->sent_at),
            'lines' => $ticket->items->map(fn (OrderItem $item) => static::line($item))->all(),
            'reason' => null,
            'by' => null,
            'notes' => $order->notes,
        ];
    }

    private static function voidData(OrderItem $item, PrintJob $job): array
    {
        $item->loadMissing('modifiers', 'parent', 'voidedBy', 'ticket.station', 'ticket.order.table', 'ticket.order.waiter', 'ticket.order.customer');

        return [
            ...static::header($item->ticket, $job),
            'heading' => '*** VOID ***',
            'void' => true,
            'time' => static::time($item->voided_at ?? now()),
            'lines' => [static::line($item, keepVoided: true)],
            'reason' => $item->void_reason,
            'by' => $item->voidedBy?->name,
            'notes' => null,
        ];
    }

    private static function header(KitchenTicket $ticket, PrintJob $job): array
    {
        $order = $ticket->order;

        return [
            'void' => false,
            'reprint' => str_ends_with($job->title, '(reprint)'),
            'kot' => $ticket->code(),
            'station' => $ticket->station?->name,
            'order' => $order->code(),
            'type' => $order->type->label(),
            'table' => $order->type === OrderType::DineIn ? $order->table?->name : null,
            'waiter' => $order->waiter?->name,
            'guests' => $order->guests,
            'customer' => $order->type !== OrderType::DineIn ? $order->customer?->name : null,
        ];
    }

    private static function line(OrderItem $item, bool $keepVoided = false): array
    {
        return [
            'quantity' => $item->quantity,
            'name' => $item->fullName(),
            'extras' => $item->modifiers->pluck('name')->all(),
            'deal' => $item->parent?->item_name,
            'note' => $item->notes,
            'voided' => ! $keepVoided && $item->isVoided(),
        ];
    }

    private static function time(CarbonInterface $at): string
    {
        return $at->format(setting('general.time_format') === '24h' ? 'd M, H:i' : 'd M, h:i A');
    }

    /** Raw ESC/POS bytes for a thermal printer. */
    public static function escPos(array $slip, int $paperWidth, int $copies = 1): string
    {
        $bytes = '';

        for ($copy = 0; $copy < $copies; $copy++) {
            $p = new EscPos($paperWidth);
            $p->center()->large()->bold()->text($slip['heading'])->large(false);
            if ($slip['void']) {
                $p->text($slip['kot']);
            }
            if ($slip['station']) {
                $p->bold()->text(mb_strtoupper($slip['station']))->bold(false);
            }
            if ($slip['reprint']) {
                $p->text('(REPRINT)');
            }
            $p->left()->rule();

            $p->large()->bold()->text(collect([$slip['order'], $slip['table']])->filter()->join('  '))->large(false)->bold(false);
            $p->pair($slip['type'].($slip['guests'] ? " - {$slip['guests']} guests" : ''), $slip['time']);
            if ($slip['waiter']) {
                $p->text("Waiter: {$slip['waiter']}");
            }
            if ($slip['customer']) {
                $p->text("Customer: {$slip['customer']}");
            }
            $p->rule();

            foreach ($slip['lines'] as $line) {
                $prefix = $line['voided'] ? 'VOID ' : '';
                $p->bold()->large()->text("{$prefix}{$line['quantity']} x {$line['name']}", 2)->large(false)->bold(false);
                if ($line['deal']) {
                    $p->text("   ({$line['deal']})", 4);
                }
                foreach ($line['extras'] as $extra) {
                    $p->text("   + {$extra}", 5);
                }
                if ($line['note']) {
                    $p->bold()->text("   ** {$line['note']}", 6)->bold(false);
                }
            }

            $p->rule();
            if ($slip['reason']) {
                $p->bold()->text("Reason: {$slip['reason']}")->bold(false);
            }
            if ($slip['by']) {
                $p->text("Voided by: {$slip['by']}");
            }
            if ($slip['notes']) {
                $p->text("Note: {$slip['notes']}");
            }
            $p->feed(3)->cut();

            $bytes .= $p->toString();
        }

        return $bytes;
    }
}
