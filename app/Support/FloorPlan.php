<?php

namespace App\Support;

use App\Enums\TableShape;
use App\Models\DiningTable;
use Illuminate\Support\Collection;

/**
 * Grid geometry of the floor plan: a table covers the cells of its shape starting at
 * (pos_x, pos_y). Used to place new tables on a free spot and to refuse overlaps.
 */
class FloorPlan
{
    /** First free top-left cell for this shape in the area (row by row), or null when full. */
    public static function freeSpot(int $areaId, TableShape $shape, ?int $ignoreId = null): ?array
    {
        [$w, $h] = $shape->size();
        $placed = static::placed($areaId, $ignoreId);

        for ($y = 0; $y <= DiningTable::GRID_ROWS - $h; $y++) {
            for ($x = 0; $x <= DiningTable::GRID_COLS - $w; $x++) {
                if (! static::hits($placed, $x, $y, $w, $h)) {
                    return [$x, $y];
                }
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, array{id: string, x: int, y: int, w: int, h: int, name: string}>  $placed
     * @return ?string name of the table this rectangle overlaps
     */
    public static function hits(Collection $placed, int $x, int $y, int $w, int $h): ?string
    {
        foreach ($placed as $p) {
            if ($x < $p['x'] + $p['w'] && $p['x'] < $x + $w && $y < $p['y'] + $p['h'] && $p['y'] < $y + $h) {
                return $p['name'];
            }
        }

        return null;
    }

    /** Rectangles of the placed tables of an area. */
    public static function placed(int $areaId, ?int $ignoreId = null): Collection
    {
        return DiningTable::query()
            ->where('area_id', $areaId)
            ->whereNotNull('pos_x')
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get()
            ->map(fn (DiningTable $t) => static::rect($t, $t->pos_x, $t->pos_y));
    }

    public static function rect(DiningTable $table, int $x, int $y): array
    {
        [$w, $h] = $table->shape->size();

        return ['id' => $table->uuid, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'name' => $table->name];
    }
}
