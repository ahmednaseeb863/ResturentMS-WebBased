<?php

namespace Database\Factories;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Admin;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> A placed takeaway order with no lines (tests build lines through the POS). */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'type' => OrderType::Takeaway,
            'source' => OrderSource::Pos,
            'status' => OrderStatus::Placed,
            'business_date' => now()->toDateString(),
            'number' => fn (array $a) => (int) Order::query()->withoutGlobalScopes()->where('branch_id', $a['branch_id'] ?? null)->max('number') + 1,
            'order_number' => fn (array $a) => str_pad((string) $a['number'], 3, '0', STR_PAD_LEFT),
            'created_by' => fn () => Admin::factory()->create()->id,
            'placed_at' => now(),
        ];
    }
}
