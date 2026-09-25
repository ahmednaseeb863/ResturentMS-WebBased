<?php

namespace App\Http\Requests;

use App\Enums\PrinterConnection;
use App\Enums\PrinterType;
use App\Models\Printer;
use App\Rules\UniqueWithTrash;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PrinterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', new UniqueWithTrash('printers', 'name', $this->printer()?->id, 'printer', ['branch_id' => app(CurrentBranch::class)->id()])],
            'type' => ['required', Rule::enum(PrinterType::class)],
            'connection' => ['required', Rule::enum(PrinterConnection::class)],
            'device_name' => ['nullable', 'required_if:connection,usb', 'string', 'max:120'],
            'ip_address' => ['nullable', 'required_if:connection,network', 'ip'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'paper_width' => ['required', Rule::in([58, 80])],
            'is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['device_name' => 'printer name on the PC', 'ip_address' => 'IP address'];
    }

    public function messages(): array
    {
        return [
            'device_name.required_if' => 'Enter the printer name exactly as it appears on the counter PC.',
            'ip_address.required_if' => 'Enter the printer’s IP address.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $printer = $this->printer();

            if ($printer && $this->input('type') !== PrinterType::Receipt->value && $printer->counters()->exists()) {
                $validator->errors()->add('type', 'Cash counters print their receipts here — pick another receipt printer for them first.');
            }
            if ($printer && $this->input('type') !== PrinterType::Kitchen->value && $printer->kitchenStations()->exists()) {
                $validator->errors()->add('type', 'Kitchen stations print their tickets here — pick another kitchen printer for them first.');
            }
        }];
    }

    public function printer(): ?Printer
    {
        return $this->route('printer');
    }

    public function printerData(): array
    {
        $network = $this->validated('connection') === PrinterConnection::Network->value;

        return [
            'name' => $this->validated('name'),
            'type' => $this->validated('type'),
            'connection_type' => $this->validated('connection'),
            'device_name' => $network ? null : $this->validated('device_name'),
            'ip_address' => $network ? $this->validated('ip_address') : null,
            'port' => $network ? ($this->validated('port') ?: Printer::DEFAULT_PORT) : null,
            'paper_width' => (int) $this->validated('paper_width'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
