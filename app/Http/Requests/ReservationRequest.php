<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\DiningTable;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A reservation: guest (a customer or just a name / phone), party size, date + time (branch
 * local time), how long it holds the table (default: Orders setting), optional table, notes.
 */
class ReservationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'customer' => ['nullable', 'uuid'],
            'guest_name' => ['required', 'string', 'max:120'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'party_size' => ['required', 'integer', 'min:1', 'max:500'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:600'],
            'table' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:500'],
            'confirmed' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return ['guest_name.required' => 'Enter the guest’s name.'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->filled('customer') && ! $this->customer()) {
                $validator->errors()->add('customer', 'Pick a customer.');
            }
            if ($this->filled('table')) {
                $table = $this->table();
                if (! $table) {
                    $validator->errors()->add('table', 'Pick an active table of this branch.');
                } elseif ($table->capacity && (int) $this->input('party_size') > $table->capacity) {
                    $validator->errors()->add('table', "{$table->name} seats {$table->capacity}.");
                }
            }
        }];
    }

    public function customer(): ?Customer
    {
        return once(fn () => $this->filled('customer') ? Customer::query()->where('uuid', $this->input('customer'))->first() : null);
    }

    public function table(): ?DiningTable
    {
        return once(fn () => $this->filled('table') ? DiningTable::query()->active()->where('uuid', $this->input('table'))->first() : null);
    }

    /** The data SaveReservation takes. */
    public function reservationData(): array
    {
        return [
            'customer' => $this->customer(),
            'guest_name' => trim($this->validated('guest_name')),
            'guest_phone' => filled($this->validated('guest_phone')) ? trim($this->validated('guest_phone')) : null,
            'party_size' => (int) $this->validated('party_size'),
            'reserved_at' => CarbonImmutable::parse($this->validated('date').' '.$this->validated('time')),
            'duration_minutes' => (int) ($this->validated('duration_minutes') ?: setting('orders.reservation_minutes')),
            'table' => $this->table(),
            'notes' => filled($this->validated('notes')) ? trim($this->validated('notes')) : null,
            'confirmed' => $this->boolean('confirmed'),
        ];
    }
}
