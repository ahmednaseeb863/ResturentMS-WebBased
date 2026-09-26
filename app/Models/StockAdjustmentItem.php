<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\TrashScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockAdjustmentItem extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'stock_quantity' => 'decimal:3', 'unit_cost' => 'decimal:4', 'line_cost' => 'decimal:2'];
    }

    public function stockable(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes([TrashScope::class]);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }
}
