<?php

namespace Database\Seeders;

use App\Enums\TableShape;
use App\Models\Area;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Support\CurrentBranch;
use Illuminate\Database\Seeder;

/**
 * Two dining areas with tables laid out on the floor plan (local development only).
 * Skipped when the branch already has areas.
 */
class DemoFloorSeeder extends Seeder
{
    public function run(?Branch $branch = null): void
    {
        $branch ??= Branch::query()->where('code', 'MAIN')->firstOrFail();

        app(CurrentBranch::class)->actingAs($branch, function () {
            if (Area::query()->withTrashed()->exists()) {
                return;
            }

            $hall = Area::create(['name' => 'Hall', 'sort_order' => 1]);
            $family = Area::create(['name' => 'Family', 'sort_order' => 2]);

            // Hall: two rows of four square tables and two round ones
            foreach (range(1, 8) as $i) {
                DiningTable::create([
                    'area_id' => $hall->id, 'name' => "T{$i}", 'capacity' => 4, 'shape' => TableShape::Square,
                    'pos_x' => 1 + (($i - 1) % 4) * 4, 'pos_y' => $i <= 4 ? 1 : 5,
                ]);
            }
            DiningTable::create(['area_id' => $hall->id, 'name' => 'T9', 'capacity' => 2, 'shape' => TableShape::Round, 'pos_x' => 19, 'pos_y' => 1]);
            DiningTable::create(['area_id' => $hall->id, 'name' => 'T10', 'capacity' => 2, 'shape' => TableShape::Round, 'pos_x' => 19, 'pos_y' => 5]);

            // Family: long tables
            foreach (range(1, 3) as $i) {
                DiningTable::create([
                    'area_id' => $family->id, 'name' => "F{$i}", 'capacity' => 8, 'shape' => TableShape::Long,
                    'pos_x' => 2, 'pos_y' => 1 + ($i - 1) * 4,
                ]);
            }
        });
    }
}
