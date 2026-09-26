<?php

namespace App\Http\Requests;

use App\Enums\OrderSource;
use App\Enums\OrderType;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DiningTable;
use App\Models\Employee;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

/**
 * Items sent from the waiter app (PLAN §4.11): a new dine-in order on a table
 * (`waiter.orders.store`, `{table}`) or more items for the table's open order
 * (`waiter.orders.update`, `{order}`). Always `send`. Only items, guests and notes
 * come from the phone — the table, the customer, discounts and service charge stay as
 * the order has them (the cashier's side); a new order gets the signed-in waiter.
 */
class WaiterOrderRequest extends PosOrderRequest
{
    protected function prepareForValidation(): void
    {
        $order = $this->order();
        $items = array_map(fn ($item) => is_array($item) ? Arr::except($item, 'discount') : $item, (array) $this->input('items', []));

        $this->replace([
            'action' => 'send',
            'type' => OrderType::DineIn->value,
            'items' => $items,
            'guests' => $this->filled('guests') ? $this->input('guests') : $order?->guests,
            'notes' => $this->exists('notes') ? $this->input('notes') : $order?->notes,
        ]);
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $order = $this->order();

                if ($order && $order->type !== OrderType::DineIn) {
                    $validator->errors()->add('order', 'Only dine-in orders are taken in the waiter app.');
                } elseif ($order?->isDraft()) {
                    $validator->errors()->add('order', 'This table’s order is held on the POS — the cashier must send it first.');
                } elseif (! $order && ! $this->table()) {
                    $validator->errors()->add('table', 'This table is switched off.');
                }
            },
            ...parent::after(),
        ];
    }

    public function source(): OrderSource
    {
        return OrderSource::WaiterApp;
    }

    public function orderType(): OrderType
    {
        return OrderType::DineIn;
    }

    public function table(): ?DiningTable
    {
        return once(fn () => $this->order()
            ? $this->order()->table
            : DiningTable::query()->active()->whereKey($this->route('table')?->id)->first());
    }

    /** The order's waiter; a new order is the signed-in waiter's. */
    public function waiter(): ?Employee
    {
        return once(fn () => $this->order()?->waiter ?? $this->user('admin')->employee);
    }

    public function customer(): ?Customer
    {
        return $this->order()?->customer;
    }

    public function address(): ?CustomerAddress
    {
        return null;
    }

    public function orderDiscount(): ?array
    {
        return null;
    }

    public function discountChanged(): bool
    {
        return false;
    }

    public function removeServiceCharge(): bool
    {
        return (bool) $this->order()?->service_charge_removed;
    }

    public function approver(): ?Admin
    {
        return null;
    }
}
