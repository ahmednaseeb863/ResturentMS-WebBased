<?php

namespace App\Actions;

use App\Enums\ConsumptionStatus;
use App\Enums\KitchenStatus;
use App\Models\Admin;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RawMaterial;
use App\Support\KitchenSync;
use App\Support\RecipeUse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks lines of a kitchen ticket ready (one line, or the whole ticket = "bump") and
 * deducts their raw materials (PLAN §4.12): with the cook's quantities when given, else
 * at recipe quantities — unless "confirm raw material used" is on, then the cook must
 * confirm them first. Ticket and order statuses follow.
 */
class MarkKitchenReady
{
    public function __construct(private ConfirmConsumption $consumption) {}

    /**
     * @param  list<int>|null  $itemIds  null = every line still being made
     * @param  array<int, array<int, array{material: RawMaterial, quantity: float, reason: ?string}>>  $consumption  order item id => raw material id => line
     */
    public function handle(KitchenTicket $ticket, ?array $itemIds, array $consumption, Admin $admin): KitchenTicket
    {
        return DB::transaction(function () use ($ticket, $itemIds, $consumption, $admin) {
            $order = Order::query()->lockForUpdate()->findOrFail($ticket->order_id);
            $ticket = KitchenTicket::query()->lockForUpdate()->findOrFail($ticket->id);

            if (! $order->isOpen()) {
                throw ValidationException::withMessages(['ticket' => "Order {$order->code()} is {$order->status->label()}."]);
            }

            $items = $ticket->liveItems()
                ->whereIn('kitchen_status', [KitchenStatus::Pending, KitchenStatus::Preparing])
                ->when($itemIds !== null, fn ($q) => $q->whereKey($itemIds))
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['ticket' => "Nothing left to make on {$ticket->code()}."]);
            }

            $mustConfirm = (bool) setting('kitchen.confirm_consumption');

            foreach ($items as $item) {
                $item->setRelation('order', $order);

                if ($item->consumption_status === ConsumptionStatus::Pending) {
                    $given = $consumption[$item->id] ?? null;
                    if ($given === null && $mustConfirm && RecipeUse::of($item)->isNotEmpty()) {
                        throw ValidationException::withMessages(['consumption' => "Confirm the raw materials used for {$item->quantity} × {$item->fullName()}."]);
                    }
                    $this->consumption->handle($item, $given, $admin);
                }

                $item->update(['kitchen_status' => KitchenStatus::Ready, 'ready_at' => now()]);
            }

            KitchenSync::ticket($ticket);
            KitchenSync::order($order);
            KitchenSync::changed($ticket->branch_id);

            return $ticket;
        });
    }

    /** Lines of the ticket whose raw materials the cook still has to confirm, with the recipe quantities. */
    public static function toConfirm(KitchenTicket $ticket, ?OrderItem $only = null): array
    {
        return $ticket->liveItems()
            ->whereIn('kitchen_status', [KitchenStatus::Pending, KitchenStatus::Preparing])
            ->where('consumption_status', ConsumptionStatus::Pending)
            ->when($only, fn ($q) => $q->whereKey($only->id))
            ->with('modifiers', 'variant', 'sellable')
            ->get()
            ->map(fn (OrderItem $item) => [
                'id' => $item->uuid,
                'name' => $item->fullName(),
                'quantity' => $item->quantity,
                'extras' => $item->modifiers->pluck('name')->all(),
                'materials' => ConfirmConsumption::plan($item),
            ])
            ->filter(fn ($row) => $row['materials'] !== [])
            ->values()
            ->all();
    }
}
