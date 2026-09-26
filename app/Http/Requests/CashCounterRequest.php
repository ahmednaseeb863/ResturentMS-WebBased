<?php

namespace App\Http\Requests;

use App\Enums\PrinterType;
use App\Models\CashCounter;
use App\Models\Printer;
use App\Rules\UniqueWithTrash;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CashCounterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', new UniqueWithTrash('cash_counters', 'name', $this->counter()?->id, 'cash counter', ['branch_id' => app(CurrentBranch::class)->id()])],
            'receipt_printer' => ['nullable', 'uuid'],
            'is_active' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->filled('receipt_printer') && ! $this->receiptPrinter()) {
                $validator->errors()->add('receipt_printer', 'Pick an active receipt printer of this branch.');
            }
            if (! $this->boolean('is_active', true) && ($open = $this->counter()?->openShift()->first())) {
                $validator->errors()->add('is_active', "Shift {$open->code()} is open on this counter — close it first.");
            }
        }];
    }

    public function counter(): ?CashCounter
    {
        return $this->route('counter');
    }

    /** Active receipt printers of the current branch (BelongsToBranch scope), or the one already set. */
    public function receiptPrinter(): ?Printer
    {
        return once(fn () => Printer::query()
            ->where('uuid', $this->input('receipt_printer'))
            ->ofType(PrinterType::Receipt)
            ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $this->counter()?->receipt_printer_id))
            ->first());
    }

    public function counterData(): array
    {
        return [
            'name' => $this->validated('name'),
            'receipt_printer_id' => $this->filled('receipt_printer') ? $this->receiptPrinter()->id : null,
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
