<?php

namespace App\Models;

use App\Enums\TableShape;
use App\Enums\TableStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dining table (`tables`). Its spot on the floor plan is a cell of a
 * GRID_COLS × GRID_ROWS grid; the shape decides how many cells it covers.
 */
class DiningTable extends Model
{
    use BelongsToBranch, HasFactory, HasPublicUuid, LogsActivity, Trashable;

    public const GRID_COLS = 24;

    public const GRID_ROWS = 14;

    protected $table = 'tables';

    protected $fillable = ['area_id', 'name', 'capacity', 'shape', 'status', 'pos_x', 'pos_y', 'is_active'];

    /** Status changes are logged on their own (`table_status`); positions are layout noise. */
    protected array $activityHidden = ['status', 'pos_x', 'pos_y'];

    protected array $trashParents = ['area'];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'shape' => TableShape::class,
            'status' => TableStatus::class,
            'pos_x' => 'integer',
            'pos_y' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class)->withTrashed();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function canBeTrashed(): true|string
    {
        return $this->status === TableStatus::Occupied
            ? 'It has an open order — close or move the order first.'
            : true;
    }

    /** @return array{0: int, 1: int} the position moved inside the grid for this shape */
    public static function clampPosition(TableShape $shape, int $x, int $y): array
    {
        [$w, $h] = $shape->size();

        return [max(0, min($x, self::GRID_COLS - $w)), max(0, min($y, self::GRID_ROWS - $h))];
    }
}
