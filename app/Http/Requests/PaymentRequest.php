<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\BankAccount;
use App\Models\BillSplit;
use App\Models\Order;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * Payment for an order (`orders.payments.store`): one tender or a split payment (cash +
 * bank transfer), for the whole bill or one part of a split bill (`split`). Cash has the
 * amount put on the bill and the cash received (change = the difference); a transfer names
 * the bank account, the reference no. and a screenshot, as the Payments settings require.
 */
class PaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'split' => ['nullable', 'uuid'],
            'tenders' => ['required', 'array', 'min:1', 'max:4'],
            'tenders.*.method' => ['required', 'in:'.implode(',', array_column(PaymentMethod::cases(), 'value'))],
            'tenders.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'tenders.*.tendered' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'tenders.*.bank' => ['nullable', 'uuid'],
            'tenders.*.reference' => ['nullable', 'string', 'max:80'],
            'tenders.*.proof' => ['nullable', 'image', 'max:4096'],
            'return' => ['nullable', 'in:pos'],
        ];
    }

    public function messages(): array
    {
        return [
            'tenders.*.proof.image' => 'The transfer screenshot must be an image.',
            'tenders.*.proof.max' => 'The transfer screenshot may be up to 4 MB.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $branch = app(CurrentBranch::class)->id();
            $cash = 0;

            foreach ($this->input('tenders', []) as $i => $tender) {
                $method = PaymentMethod::from($tender['method']);
                if ((float) $tender['amount'] <= 0) {
                    continue;
                }

                if ($method === PaymentMethod::Cash) {
                    $cash++;
                    if (isset($tender['tendered']) && (float) $tender['tendered'] < (float) $tender['amount']) {
                        $validator->errors()->add("tenders.{$i}.tendered", 'Cash received is less than the cash amount.');
                    }

                    continue;
                }

                if (! $this->bank($i)) {
                    $validator->errors()->add("tenders.{$i}.bank", 'Pick the bank account the money was sent to.');
                }
                if (setting('payments.transfer_reference_required', $branch) && blank($tender['reference'] ?? null)) {
                    $validator->errors()->add("tenders.{$i}.reference", 'Enter the transfer reference no.');
                }
                if (setting('payments.transfer_proof_required', $branch) && ! $this->file("tenders.{$i}.proof")) {
                    $validator->errors()->add("tenders.{$i}.proof", 'Attach the transfer screenshot.');
                }
            }

            if ($cash > 1) {
                $validator->errors()->add('tenders', 'Enter cash once.');
            }
            if ($this->filled('split') && ! $this->split()) {
                $validator->errors()->add('split', 'That part of the bill no longer exists — split the bill again.');
            }
        }];
    }

    public function order(): Order
    {
        return $this->route('order');
    }

    public function split(): ?BillSplit
    {
        return once(fn () => $this->filled('split')
            ? $this->order()->splits()->where('uuid', $this->input('split'))->first()
            : null);
    }

    /** An active bank account available at the current branch. */
    private function bank(int $index): ?BankAccount
    {
        $uuid = $this->input("tenders.{$index}.bank");

        return $uuid
            ? BankAccount::query()->active()->availableAt(app(CurrentBranch::class)->id())->where('uuid', $uuid)->first()
            : null;
    }

    /** @return list<array{method: PaymentMethod, amount: float, tendered: ?float, bank: ?BankAccount, reference: ?string, proof: ?UploadedFile}> */
    public function tenders(): array
    {
        $tenders = [];

        foreach ($this->validated('tenders') as $i => $tender) {
            $method = PaymentMethod::from($tender['method']);
            $tenders[] = [
                'method' => $method,
                'amount' => round((float) $tender['amount'], 2),
                'tendered' => isset($tender['tendered']) ? round((float) $tender['tendered'], 2) : null,
                'bank' => $method === PaymentMethod::BankTransfer ? $this->bank($i) : null,
                'reference' => $method === PaymentMethod::BankTransfer ? (trim((string) ($tender['reference'] ?? '')) ?: null) : null,
                'proof' => $method === PaymentMethod::BankTransfer ? $this->file("tenders.{$i}.proof") : null,
            ];
        }

        return $tenders;
    }
}
