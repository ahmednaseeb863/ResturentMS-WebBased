<?php

namespace Database\Factories;

use App\Enums\PrinterConnection;
use App\Enums\PrinterType;
use App\Models\Branch;
use App\Models\Printer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Printer> Pass `branch_id` (forBranch) or run inside CurrentBranch::actingAs(). */
class PrinterFactory extends Factory
{
    protected $model = Printer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('Printer ##??'),
            'type' => PrinterType::Receipt,
            'connection_type' => PrinterConnection::Usb,
            'device_name' => 'EPSON TM-T20III',
            'paper_width' => 80,
            'is_active' => true,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(['branch_id' => $branch->id]);
    }

    public function kitchen(): static
    {
        return $this->state(['type' => PrinterType::Kitchen]);
    }

    public function network(string $ip = '192.168.1.50'): static
    {
        return $this->state(['connection_type' => PrinterConnection::Network, 'device_name' => null, 'ip_address' => $ip, 'port' => 9100]);
    }
}
