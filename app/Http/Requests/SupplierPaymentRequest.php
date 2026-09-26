<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\BankAccount;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Paying a supplier: amount, cash (out of my open shift's drawer) or bank transfer (an
 * account of this branch, reference no.), against one purchase (`purchase`) or on account.
 */
class SupplierPaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'purchase' => ['nullable', 'uuid'],
            ...static::paymentRules('', true),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->filled('purchase') && ! $this->purchase()) {
                $validator->errors()->add('purchase', 'That purchase is not from this supplier.');
            }
            static::checkPayment($this, $validator, '');
        }];
    }

    /** Rules of a payment under `$prefix` (`pay.` inside the purchase form). */
    public static function paymentRules(string $prefix, bool $required): array
    {
        return [
            "{$prefix}amount" => [$required ? 'required' : 'nullable', 'numeric', $required ? 'gt:0' : 'min:0', 'max:9999999999.99'],
            "{$prefix}method" => ['nullable', 'in:'.PaymentMethod::Cash->value.','.PaymentMethod::BankTransfer->value],
            "{$prefix}bank" => ['nullable', 'uuid'],
            "{$prefix}reference" => ['nullable', 'string', 'max:80'],
            "{$prefix}notes" => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function checkPayment(FormRequest $request, Validator $validator, string $prefix): void
    {
        $method = PaymentMethod::tryFrom((string) $request->input("{$prefix}method")) ?? PaymentMethod::Cash;
        if ($method === PaymentMethod::BankTransfer && ! static::bankOf($request, $prefix)) {
            $validator->errors()->add("{$prefix}bank", 'Pick the bank account the money was sent from.');
        }
    }

    public static function methodOf(FormRequest $request, string $prefix = ''): PaymentMethod
    {
        return PaymentMethod::tryFrom((string) $request->input("{$prefix}method")) ?? PaymentMethod::Cash;
    }

    public static function bankOf(FormRequest $request, string $prefix = ''): ?BankAccount
    {
        $uuid = $request->input("{$prefix}bank");

        return $uuid ? BankAccount::query()->active()->availableAt(app(CurrentBranch::class)->id())->where('uuid', $uuid)->first() : null;
    }

    public function supplier(): Supplier
    {
        return $this->route('supplier');
    }

    public function purchase(): ?Purchase
    {
        return once(fn () => $this->filled('purchase')
            ? Purchase::query()->where('supplier_id', $this->supplier()->id)->where('uuid', $this->input('purchase'))->first()
            : null);
    }
}
