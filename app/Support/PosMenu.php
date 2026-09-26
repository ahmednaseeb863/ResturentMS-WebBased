<?php

namespace App\Support;

use App\Enums\DesignationType;
use App\Enums\EmployeeStatus;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Deal;
use App\Models\DealSlot;
use App\Models\DealSlotOption;
use App\Models\DiningTable;
use App\Models\Discount;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\ReadyItem;
use Carbon\CarbonImmutable;

/**
 * What the POS screen shows (current branch, uuids only): categories, sellable menu items
 * (sizes, add-on groups), ready items with stock, deals available now, tables with their
 * open order, waiters and running discounts. The server re-checks everything on save.
 */
class PosMenu
{
    public static function categories(): array
    {
        return Category::query()->active()->ordered()->get()
            ->map(fn (Category $c) => ['id' => $c->uuid, 'name' => $c->name])->all();
    }

    /** Menu items, ready items and deals in one list (`key` = "type:uuid"). */
    public static function items(): array
    {
        $menu = MenuItem::query()->where('is_active', true)
            ->with(['category', 'variants', 'modifierGroups' => fn ($q) => $q->where('is_active', true), 'modifierGroups.modifiers' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (MenuItem $m) => $m->category?->is_active !== false)
            ->map(fn (MenuItem $m) => [
                'key' => "menu_item:{$m->uuid}",
                'type' => 'menu_item',
                'id' => $m->uuid,
                'name' => $m->name,
                'description' => $m->description,
                'price' => (float) $m->price,
                'category' => $m->category?->uuid,
                'image' => $m->imageUrl(),
                'available_for' => $m->available_for ?? [],
                'sold_out' => $m->is_sold_out,
                'variants' => $m->variants->map(fn ($v) => [
                    'id' => $v->uuid, 'name' => $v->name, 'price' => (float) $v->price, 'is_default' => $v->is_default,
                ])->values()->all(),
                'groups' => $m->modifierGroups->map(fn (ModifierGroup $g) => [
                    'id' => $g->uuid,
                    'name' => $g->name,
                    'min' => $g->min_select,
                    'max' => $g->max_select,
                    'rule' => $g->ruleText(),
                    'modifiers' => $g->modifiers->map(fn ($mod) => ['id' => $mod->uuid, 'name' => $mod->name, 'price' => (float) $mod->price])->values()->all(),
                ])->filter(fn ($g) => $g['modifiers'])->values()->all(),
            ]);

        $ready = ReadyItem::query()->where('is_active', true)->with(['category', 'stockUnit'])
            ->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (ReadyItem $r) => $r->category?->is_active !== false)
            ->map(fn (ReadyItem $r) => [
                'key' => "ready_item:{$r->uuid}",
                'type' => 'ready_item',
                'id' => $r->uuid,
                'name' => $r->name,
                'price' => (float) $r->price,
                'category' => $r->category?->uuid,
                'image' => $r->imageUrl(),
                'available_for' => $r->available_for ?? [],
                'stock' => (float) $r->current_stock,
                'low' => $r->isLowStock(),
                'unit' => $r->stockUnit?->short_name,
                'codes' => array_values(array_filter([$r->code, $r->barcode])),
                'kitchen' => $r->kitchen_station_id !== null,
            ]);

        return $menu->concat($ready)->values()->all();
    }

    /** Deals that can be sold right now (the order type is checked on the POS and on save). */
    public static function deals(): array
    {
        $now = CarbonImmutable::now();

        return Deal::query()->runningOn(BusinessDate::for())
            ->with(['slots.options.sellable', 'slots.options.variant'])
            ->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (Deal $d) => $d->isAvailableAt($now))
            ->map(fn (Deal $d) => [
                'key' => "deal:{$d->uuid}",
                'type' => 'deal',
                'id' => $d->uuid,
                'name' => $d->name,
                'description' => $d->description,
                'price' => (float) $d->price,
                'image' => $d->imageUrl(),
                'available_for' => $d->available_for ?? [],
                'schedule' => $d->scheduleText(),
                'slots' => $d->slots->map(fn (DealSlot $s) => [
                    'id' => $s->uuid,
                    'name' => $s->name,
                    'quantity' => $s->quantity,
                    'options' => $s->options
                        ->filter(fn (DealSlotOption $o) => $o->sellable && $o->sellable->is_active && ! $o->sellable->isTrashed() && ! ($o->sellable->is_sold_out ?? false))
                        ->map(fn (DealSlotOption $o) => [
                            'id' => $o->uuid,
                            'label' => $o->label(),
                            'extra' => (float) $o->extra_price,
                            'is_default' => $o->is_default,
                        ])->values()->all(),
                ])->values()->all(),
            ])
            ->values()->all();
    }

    /** Active tables with the open order sitting on them. */
    public static function tables(): array
    {
        $open = Order::query()->open()->whereNotNull('table_id')->get()->keyBy('table_id');

        return DiningTable::query()->active()->with('area')->get()
            ->sortBy([fn ($a, $b) => [$a->area?->sort_order, $a->area?->name] <=> [$b->area?->sort_order, $b->area?->name], fn ($a, $b) => strnatcasecmp($a->name, $b->name)])
            ->map(fn (DiningTable $t) => [
                'id' => $t->uuid,
                'name' => $t->name,
                'area' => $t->area?->name,
                'capacity' => $t->capacity,
                'status' => $t->status->value,
                'order' => ($o = $open->get($t->id)) ? ['id' => $o->uuid, 'code' => $o->code(), 'total' => $o->grand_total] : null,
            ])->values()->all();
    }

    public static function waiters(): array
    {
        return Employee::query()->where('status', EmployeeStatus::Active)
            ->whereHas('designation', fn ($q) => $q->where('type', DesignationType::Waiter))
            ->orderBy('name')->get()
            ->map(fn (Employee $e) => ['value' => $e->uuid, 'label' => $e->name])->all();
    }

    /** Predefined discounts running today. */
    public static function discounts(): array
    {
        return Discount::query()->runningOn(BusinessDate::for())->orderBy('name')->get()
            ->map(fn (Discount $d) => [
                'id' => $d->uuid,
                'name' => $d->name,
                'type' => $d->type->value,
                'value' => (float) $d->value,
                'applies_to' => $d->applies_to->value,
                'max_amount' => $d->max_amount === null ? null : (float) $d->max_amount,
                'min_amount' => $d->min_order_amount === null ? null : (float) $d->min_order_amount,
                'requires_approval' => $d->requires_approval,
            ])->all();
    }

    /** Bill rules the cart uses to show its totals (the server recalculates on save). */
    public static function rules(): array
    {
        return [
            'types' => collect(['dine_in', 'takeaway', 'delivery'])->filter(fn ($t) => setting("orders.{$t}"))->values()->all(),
            'hold' => (bool) setting('orders.hold_orders'),
            'customer_for_delivery' => (bool) setting('orders.require_customer_for_delivery'),
            'service_charge' => setting('service_charge.enabled') ? (float) setting('service_charge.rate') : 0,
            'service_removable' => (bool) setting('service_charge.removable'),
            'tax_name' => setting('tax.name'),
            'tax_rate' => setting('tax.enabled') ? (float) setting('tax.rate') : 0,
            'delivery_fee' => (float) setting('delivery.default_fee'),
            'delivery_minimum' => (float) setting('delivery.min_order_amount'),
            'rounding' => (string) setting('payments.rounding'),
            'out_of_stock' => setting('inventory.out_of_stock'),
            'pin_discount_above' => (float) setting('approvals.pin_discount_above'),
            'pin_service_charge' => (bool) setting('approvals.pin_remove_service_charge'),
            'pin_void' => (bool) setting('approvals.pin_void'),
            'pin_refund' => (bool) setting('approvals.pin_refund'),
            'cash' => (bool) setting('payments.cash'),
            'bank_transfer' => (bool) setting('payments.bank_transfer'),
            'transfer_reference_required' => (bool) setting('payments.transfer_reference_required'),
            'transfer_proof_required' => (bool) setting('payments.transfer_proof_required'),
        ];
    }

    /** Bank accounts a transfer can be paid into at this branch. */
    public static function bankAccounts(): array
    {
        return BankAccount::query()->active()->availableAt(app(CurrentBranch::class)->id())->orderBy('bank_name')->get()
            ->map(fn (BankAccount $b) => ['value' => $b->uuid, 'label' => $b->trashLabel(), 'number' => $b->account_number])->all();
    }
}
