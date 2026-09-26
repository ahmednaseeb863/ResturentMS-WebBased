<?php

namespace App\Http\Requests;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\EmployeeStatus;
use App\Enums\OrderType;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DiningTable;
use App\Models\Discount;
use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Support\BusinessDate;
use App\Support\CartError;
use App\Support\CartLine;
use App\Support\CurrentBranch;
use App\Support\ManagerApproval;
use App\Support\OrderCart;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * The POS cart + order details (PLAN §4.10), for a new order (`pos.orders.store`) or an
 * order already saved (`pos.orders.update`). `action`: hold (park a new order), send
 * (place it / send new items to the kitchen) or save (details only).
 *
 * `items` are the lines not sent yet (all lines of a held order). `discount` and
 * `remove_service_charge` are the order's full state: discounts need `orders.discount`,
 * removing service charge `orders.service-charge`, and a manager PIN when the
 * Security & Approvals settings say so.
 */
class PosOrderRequest extends FormRequest
{
    private ?array $lines = null;

    public function rules(): array
    {
        $discount = fn (string $prefix) => [
            "{$prefix}" => ['nullable', 'array'],
            "{$prefix}.discount" => ['nullable', 'uuid'],
            "{$prefix}.type" => ['nullable', 'in:percent,fixed'],
            "{$prefix}.value" => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            "{$prefix}.reason" => ['nullable', 'string', 'max:255'],
        ];

        return [
            'action' => ['required', 'in:hold,send,save'],
            'type' => [$this->order() ? 'nullable' : 'required', Rule::in(OrderType::values())],
            'table' => ['nullable', 'uuid'],
            'waiter' => ['nullable', 'uuid'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:500'],
            'customer' => ['nullable', 'uuid'],
            'address' => ['nullable', 'uuid'],
            'address_text' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['nullable', 'array', 'max:100'],
            'items.*.type' => ['required', 'in:menu_item,ready_item,deal'],
            'items.*.id' => ['required', 'uuid'],
            'items.*.variant' => ['nullable', 'uuid'],
            'items.*.modifiers' => ['nullable', 'array', 'max:30'],
            'items.*.modifiers.*' => ['uuid'],
            'items.*.picks' => ['nullable', 'array', 'max:20'],
            'items.*.picks.*.slot' => ['required', 'uuid'],
            'items.*.picks.*.option' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.notes' => ['nullable', 'string', 'max:150'],
            ...$discount('items.*.discount'),
            ...$discount('discount'),
            'remove_service_charge' => ['boolean'],
            'pin' => ['nullable', 'digits_between:4,6'],
        ];
    }

    public function messages(): array
    {
        return ['pin.digits_between' => 'A PIN is 4 to 6 digits.', 'items.max' => 'Send the order in parts of up to 100 lines.'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $errors = $validator->errors();
            $order = $this->order();
            $admin = $this->user('admin');

            if ($order && ! $order->isOpen()) {
                $errors->add('order', "Order {$order->code()} is {$order->status->label()} — it can't be changed.");

                return;
            }
            if ((! $order || $order->isDraft()) && ! setting('orders.'.$this->orderType()->value)) {
                $errors->add('type', $this->orderType()->label().' orders are switched off for this branch.');
            }
            if ($this->filled('table') && ! $this->table()) {
                $errors->add('table', 'Pick an active table of this branch.');
            }
            if ($this->filled('waiter') && ! $this->waiter()) {
                $errors->add('waiter', 'Pick an active waiter of this branch.');
            }
            if ($this->filled('customer') && ! $this->customer()) {
                $errors->add('customer', 'This customer no longer exists — pick again.');
            }
            if ($this->filled('address') && ! $this->address()) {
                $errors->add('address', 'Pick one of the customer’s addresses.');
            }

            try {
                $this->lines();
            } catch (ValidationException $e) {
                foreach ($e->errors() as $key => $messages) {
                    $errors->add($key, $messages[0]);
                }
            }
            try {
                $this->orderDiscount();
            } catch (CartError $e) {
                $errors->add('discount', $e->getMessage());
            }
            if ($errors->isNotEmpty()) {
                return;
            }

            if (($this->discountChanged() || collect($this->lines())->contains(fn (CartLine $l) => $l->discount)) && ! $admin->canRoute('orders.discount')) {
                $errors->add('discount', 'You may not give discounts.');
            }
            if ($this->serviceChargeRemovedNow()) {
                if (! setting('service_charge.removable')) {
                    $errors->add('remove_service_charge', 'Service charge can’t be removed in this branch.');
                } elseif (! $admin->canRoute('orders.service-charge')) {
                    $errors->add('remove_service_charge', 'You may not remove the service charge.');
                }
            }
        }];
    }

    // ── what was sent, as models ─────────────────────────────────────────

    public function order(): ?Order
    {
        return $this->route('order');
    }

    public function action(): string
    {
        return $this->validated('action');
    }

    /** A saved (placed) order keeps its type; a held one may still change it. */
    public function orderType(): OrderType
    {
        $order = $this->order();

        return $order && ! $order->isDraft()
            ? $order->type
            : OrderType::tryFrom((string) $this->input('type')) ?? $order?->type ?? OrderType::Takeaway;
    }

    public function table(): ?DiningTable
    {
        return once(fn () => $this->filled('table') ? DiningTable::query()->active()->where('uuid', $this->input('table'))->first() : null);
    }

    public function waiter(): ?Employee
    {
        return once(fn () => $this->filled('waiter')
            ? Employee::query()->where('status', EmployeeStatus::Active)->where('uuid', $this->input('waiter'))->first()
            : null);
    }

    public function customer(): ?Customer
    {
        return once(fn () => $this->filled('customer') ? Customer::query()->where('uuid', $this->input('customer'))->first() : null);
    }

    public function address(): ?CustomerAddress
    {
        return once(fn () => $this->filled('address') && $this->customer()
            ? $this->customer()->addresses()->where('uuid', $this->input('address'))->first()
            : null);
    }

    /** @return list<CartLine> */
    public function lines(): array
    {
        return $this->lines ??= OrderCart::read($this->input('items', []), $this->orderType());
    }

    /** @return array{preset: ?Discount, type: DiscountType, value: float, reason: ?string}|null */
    public function orderDiscount(): ?array
    {
        return once(function () {
            $data = $this->input('discount');

            if (! is_array($data) || (empty($data['discount']) && empty($data['value']))) {
                return null;
            }

            $presets = Discount::query()->where('uuid', $data['discount'] ?? '')->get()->keyBy('uuid');

            return OrderCart::discount($data, $presets, BusinessDate::for(), DiscountScope::Order);
        });
    }

    /** Is the order discount different from the one the order has? */
    public function discountChanged(): bool
    {
        $wanted = $this->orderDiscount();
        $current = $this->order()?->orderDiscount;

        if (! $wanted || ! $current) {
            return (bool) $wanted !== (bool) $current;
        }

        return $current->discount_id !== $wanted['preset']?->id
            || $current->type !== $wanted['type']
            || (float) $current->value !== (float) $wanted['value'];
    }

    public function removeServiceCharge(): bool
    {
        return $this->orderType() === OrderType::DineIn && $this->boolean('remove_service_charge');
    }

    public function serviceChargeRemovedNow(): bool
    {
        return $this->removeServiceCharge() && ! $this->order()?->service_charge_removed;
    }

    /**
     * Manager approval for what needs it (Security & Approvals settings). Call it
     * **outside** DB transactions — the PIN rate limiter lives in the DB cache.
     */
    public function approver(): ?Admin
    {
        $routes = [];

        foreach ($this->lines() as $line) {
            if ($line->discount && static::discountNeedsApproval($line->discount['preset'], $line->discountAmount, $line->gross())) {
                $routes[] = 'orders.discount';
            }
        }
        if ($this->discountChanged() && ($wanted = $this->orderDiscount())) {
            $base = $this->discountBase();
            $amount = OrderDiscount::calculate($wanted['type'], $wanted['value'], $base, $wanted['preset']?->max_amount, $wanted['preset']?->min_order_amount);
            if (static::discountNeedsApproval($wanted['preset'], $amount, $base)) {
                $routes[] = 'orders.discount';
            }
        }
        if ($this->serviceChargeRemovedNow() && setting('approvals.pin_remove_service_charge')) {
            $routes[] = 'orders.service-charge';
        }

        return static::approve(array_unique($routes), $this->validated('pin'));
    }

    /** Verify one PIN for every route that needs approval (the approver must hold them all). */
    public static function approve(array $routes, ?string $pin): ?Admin
    {
        if (! $routes) {
            return null;
        }

        $branch = app(CurrentBranch::class)->get();
        $approver = ManagerApproval::verify($pin, array_shift($routes), $branch);

        foreach ($routes as $route) {
            if (! $approver->canRoute($route)) {
                throw ValidationException::withMessages(['pin' => "{$approver->name} may not approve this — another manager must enter their PIN."]);
            }
        }

        return $approver;
    }

    /** Predefined discounts marked "needs approval"; typed-in ones above the % in the settings (0 = all). */
    public static function discountNeedsApproval(?Discount $preset, float $amount, float $base): bool
    {
        if ($preset) {
            return $preset->requires_approval;
        }

        $limit = (float) setting('approvals.pin_discount_above');

        return $limit <= 0 || ($base > 0 && $amount / $base * 100 > $limit + 0.001);
    }

    /** Items after line discounts: the sent live lines plus the new ones. */
    private function discountBase(): float
    {
        $order = $this->order();
        $sent = $order && ! $order->isDraft()
            ? $order->lines()->live()->get()->sum(fn ($i) => $i->gross() - (float) $i->discount_amount)
            : 0;

        return round($sent + collect($this->lines())->sum(fn (CartLine $l) => $l->gross() - $l->discountAmount), 2);
    }
}
