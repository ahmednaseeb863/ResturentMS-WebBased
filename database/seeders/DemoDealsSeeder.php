<?php

namespace Database\Seeders;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Deal;
use App\Models\DealSlot;
use App\Models\DealSlotOption;
use App\Models\Discount;
use App\Models\MenuItem;
use App\Models\ReadyItem;
use App\Support\CurrentBranch;
use Illuminate\Database\Seeder;

/**
 * A sample deal (Zinger Meal) and two discounts for a branch (local development only).
 * Needs the demo menu; skipped when the branch already has deals.
 */
class DemoDealsSeeder extends Seeder
{
    public function run(?Branch $branch = null): void
    {
        $branch ??= Branch::query()->where('code', 'MAIN')->firstOrFail();

        app(CurrentBranch::class)->actingAs($branch, function () {
            if (Deal::query()->withTrashed()->exists()) {
                return;
            }

            $zinger = MenuItem::query()->where('name', 'Zinger Burger')->with('variants')->first();
            $coke = ReadyItem::query()->where('name', 'Coke 1.5L')->first();

            if ($zinger && $coke) {
                $deal = Deal::create([
                    'name' => 'Zinger Meal',
                    'description' => 'Zinger burger with a 1.5L drink',
                    'price' => 799,
                    'start_time' => '12:00',
                    'end_time' => '23:00',
                    'available_for' => OrderType::values(),
                ]);

                $burger = DealSlot::create(['deal_id' => $deal->id, 'name' => 'Burger', 'quantity' => 1]);
                foreach ($zinger->variants as $i => $variant) {
                    DealSlotOption::create([
                        'deal_slot_id' => $burger->id, 'sellable_type' => 'menu_item', 'sellable_id' => $zinger->id,
                        'variant_id' => $variant->id, 'extra_price' => $i === 0 ? 0 : 250,
                        'is_default' => $i === 0, 'sort_order' => $i,
                    ]);
                }

                $drink = DealSlot::create(['deal_id' => $deal->id, 'name' => 'Drink', 'quantity' => 1, 'sort_order' => 1]);
                DealSlotOption::create([
                    'deal_slot_id' => $drink->id, 'sellable_type' => 'ready_item', 'sellable_id' => $coke->id, 'is_default' => true,
                ]);
            }

            Discount::create(['name' => 'Staff meal', 'type' => DiscountType::Percent, 'value' => 25, 'applies_to' => DiscountScope::Order, 'requires_approval' => true]);
            Discount::create(['name' => 'Rs 100 off above 1500', 'type' => DiscountType::Fixed, 'value' => 100, 'applies_to' => DiscountScope::Order, 'min_order_amount' => 1500]);
        });
    }
}
