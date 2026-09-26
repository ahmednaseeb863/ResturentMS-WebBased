<?php

namespace App\Actions;

use App\Models\Admin;
use App\Models\BillSplit;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Activity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Split bill (PLAN §4.13): the order is paid in parts — equally (N shares of the grand
 * total) or by items (each guest's items, with their share of discount, service charge,
 * tax and round-off, in proportion). Cents left over go to the last part. The split can
 * change only before any money is taken; replaced parts are trashed.
 */
class SplitBill
{
    /**
     * @param  'equal'|'items'|null  $mode  null removes the split
     * @param  int  $parts  number of equal parts
     * @param  list<list<array{item: OrderItem, quantity: int}>>  $assignments  by items: the lines of each part
     */
    public function handle(Order $order, ?string $mode, int $parts, array $assignments, Admin $admin): Order
    {
        return DB::transaction(function () use ($order, $mode, $parts, $assignments, $admin) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $order->isOpen() || $order->isDraft()) {
                throw ValidationException::withMessages(['split' => "Order {$order->code()} can't be split."]);
            }
            if ((float) $order->paid_total > 0) {
                throw ValidationException::withMessages(['split' => 'Money was already taken — the split can’t change now.']);
            }

            foreach ($order->splits as $old) {
                $old->trash($mode ? 'Split again' : 'Split removed');
            }

            if (! $mode) {
                $order->update(['split_mode' => null]);
                Activity::log('bill_split', $order, ['split' => 'removed']);

                return $order;
            }

            $grand = (float) $order->grand_total;
            $rows = $mode === 'equal'
                ? static::equalParts($grand, $parts)
                : static::itemParts($order, $grand, $assignments);

            foreach ($rows as $i => $row) {
                $order->splits()->create([
                    'branch_id' => $order->branch_id,
                    'number' => $i + 1,
                    'label' => 'Guest '.($i + 1),
                    'amount' => $row['amount'],
                    'items' => $row['items'] ?? null,
                    'created_by' => $admin->id,
                ]);
            }

            $order->update(['split_mode' => $mode]);
            Activity::log('bill_split', $order, [
                'split' => ($mode === 'equal' ? 'equally' : 'by items').' into '.count($rows),
                'parts' => array_map(fn ($r) => money($r['amount'], $order->branch_id), $rows),
            ]);

            return $order;
        });
    }

    /** @return list<array{amount: float}> */
    public static function equalParts(float $grand, int $parts): array
    {
        $parts = max(2, min(20, $parts));
        $share = floor($grand * 100 / $parts) / 100;
        $rows = array_fill(0, $parts, ['amount' => $share]);
        $rows[$parts - 1]['amount'] = round($grand - $share * ($parts - 1), 2);

        return $rows;
    }

    /**
     * Every live line must be given out in full. Each part pays its items' value (after line
     * discounts) as a share of the whole bill.
     *
     * @param  list<list<array{item: OrderItem, quantity: int}>>  $assignments
     * @return list<array{amount: float, items: list<array{order_item_id: int, quantity: int}>}>
     */
    public static function itemParts(Order $order, float $grand, array $assignments): array
    {
        $assignments = array_values(array_filter($assignments));
        if (count($assignments) < 2) {
            throw ValidationException::withMessages(['split' => 'Give items to at least two guests.']);
        }

        $lines = $order->lines()->live()->get()->keyBy('id');
        $given = [];
        $weights = [];
        $items = [];

        foreach ($assignments as $p => $part) {
            $weights[$p] = 0.0;
            foreach ($part as ['item' => $item, 'quantity' => $quantity]) {
                $line = $lines->get($item->id);
                if (! $line) {
                    throw ValidationException::withMessages(['split' => "“{$item->fullName()}” is not on the bill any more."]);
                }
                $given[$line->id] = ($given[$line->id] ?? 0) + $quantity;
                $weights[$p] += ($line->gross() - (float) $line->discount_amount) / $line->quantity * $quantity;
                $items[$p][] = ['order_item_id' => $line->id, 'quantity' => $quantity];
            }
        }

        foreach ($lines as $line) {
            if (($given[$line->id] ?? 0) !== $line->quantity) {
                throw ValidationException::withMessages(['split' => "Give all {$line->quantity} × {$line->fullName()} to the guests."]);
            }
        }

        $total = array_sum($weights);
        $rows = [];
        $sum = 0.0;
        foreach ($assignments as $p => $part) {
            $amount = $total > 0 ? round($grand * $weights[$p] / $total, 2) : 0.0;
            $rows[$p] = ['amount' => $amount, 'items' => $items[$p]];
            $sum += $amount;
        }
        $last = count($rows) - 1;
        $rows[$last]['amount'] = round($rows[$last]['amount'] + $grand - $sum, 2);

        return $rows;
    }

    /**
     * The bill changed after it was split (items added, discount…). Unpaid parts are dropped;
     * when some parts already have money, the rest of the bill becomes one new part.
     */
    public static function afterRepricing(Order $order): void
    {
        $splits = $order->splits()->with('payments')->get();
        if ($splits->isEmpty()) {
            return;
        }
        if ((float) $splits->sum('amount') === (float) $order->grand_total) {
            return;
        }

        $started = $splits->filter(fn (BillSplit $s) => $s->payments->isNotEmpty());
        $splits->diff($started)->each(fn (BillSplit $s) => $s->trash('Bill changed'));

        if ($started->isEmpty()) {
            $order->forceFill(['split_mode' => null])->save();

            return;
        }

        $rest = round((float) $order->grand_total - (float) $started->sum('amount'), 2);
        if ($rest > 0) {
            $order->splits()->create([
                'branch_id' => $order->branch_id,
                'number' => $started->max('number') + 1,
                'label' => 'Rest of the bill',
                'amount' => $rest,
                'created_by' => Auth::guard('admin')->id() ?? $order->created_by,
            ]);
        }
    }
}
