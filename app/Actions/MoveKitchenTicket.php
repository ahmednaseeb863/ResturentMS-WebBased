<?php

namespace App\Actions;

use App\Enums\KitchenStatus;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Support\Activity;
use App\Support\KitchenSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kitchen board moves of a whole ticket (ready is MarkKitchenReady):
 *   start  — pending lines → preparing
 *   serve  — a ready ticket is served / collected (leaves the board)
 *   recall — a served ticket comes back as ready; a ready one back to preparing
 * Raw materials already confirmed stay deducted on recall.
 */
class MoveKitchenTicket
{
    public function start(KitchenTicket $ticket): KitchenTicket
    {
        return $this->move($ticket, function (KitchenTicket $ticket) {
            $this->lines($ticket, [KitchenStatus::Pending], KitchenStatus::Preparing, [], 'Nothing on this ticket is waiting to start.');
        });
    }

    public function serve(KitchenTicket $ticket): KitchenTicket
    {
        return $this->move($ticket, function (KitchenTicket $ticket) {
            if ($ticket->status !== KitchenStatus::Ready) {
                throw ValidationException::withMessages(['ticket' => "{$ticket->code()} is not ready yet."]);
            }
            $this->lines($ticket, [KitchenStatus::Ready], KitchenStatus::Served, ['served_at' => now()], "{$ticket->code()} is not ready yet.");
        });
    }

    public function recall(KitchenTicket $ticket): KitchenTicket
    {
        return $this->move($ticket, function (KitchenTicket $ticket) {
            match ($ticket->status) {
                KitchenStatus::Served => $this->lines($ticket, [KitchenStatus::Served], KitchenStatus::Ready, ['served_at' => null]),
                KitchenStatus::Ready => $this->lines($ticket, [KitchenStatus::Ready], KitchenStatus::Preparing, ['ready_at' => null]),
                default => throw ValidationException::withMessages(['ticket' => "{$ticket->code()} is still being made."]),
            };
            Activity::log('kitchen_ticket_recalled', $ticket->order, ['ticket' => $ticket->code()]);
        });
    }

    private function move(KitchenTicket $ticket, callable $change): KitchenTicket
    {
        return DB::transaction(function () use ($ticket, $change) {
            $order = Order::query()->lockForUpdate()->findOrFail($ticket->order_id);
            $ticket = KitchenTicket::query()->lockForUpdate()->findOrFail($ticket->id);

            if (! $order->isOpen()) {
                throw ValidationException::withMessages(['ticket' => "Order {$order->code()} is {$order->status->label()}."]);
            }

            $ticket->setRelation('order', $order);
            $change($ticket);

            KitchenSync::ticket($ticket);
            KitchenSync::order($order);
            KitchenSync::changed($ticket->branch_id);

            return $ticket;
        });
    }

    /** @param  list<KitchenStatus>  $from */
    private function lines(KitchenTicket $ticket, array $from, KitchenStatus $to, array $extra = [], ?string $none = null): void
    {
        $moved = $ticket->liveItems()->whereIn('kitchen_status', $from)
            ->update(['kitchen_status' => $to, ...$extra, 'updated_at' => now()]);

        if (! $moved && $none !== null) {
            throw ValidationException::withMessages(['ticket' => $none]);
        }
    }
}
