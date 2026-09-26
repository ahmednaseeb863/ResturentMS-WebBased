<?php

namespace Database\Factories;

use App\Enums\ShiftStatus;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\CashCounter;
use App\Models\Shift;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift> An open shift; a counter of the same branch and a cashier are made
 * when none is given. Use `closed()` for a closed one.
 */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        return [
            'number' => fn (array $attributes) => (int) Shift::query()->withoutGlobalScope('branch')
                ->where('branch_id', $attributes['branch_id'] ?? app(CurrentBranch::class)->id())->max('number') + 1,
            'cash_counter_id' => fn (array $attributes) => CashCounter::factory()->create([
                'branch_id' => $attributes['branch_id'] ?? app(CurrentBranch::class)->id(),
            ])->id,
            'business_date' => now()->toDateString(),
            'status' => ShiftStatus::Open,
            'opened_by' => fn () => Admin::factory()->create()->id,
            'opened_at' => now(),
            'opening_cash' => 5000,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(['branch_id' => $branch->id]);
    }

    public function closed(float $counted = 5000, float $expected = 5000, float $floatLeft = 0): static
    {
        return $this->state(fn () => [
            'status' => ShiftStatus::Closed,
            'closed_at' => now(),
            'expected_cash' => $expected,
            'counted_cash' => $counted,
            'difference' => round($counted - $expected, 2),
            'float_left' => $floatLeft,
            'handed_over_amount' => round($counted - $floatLeft, 2),
        ]);
    }
}
