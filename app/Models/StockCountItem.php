<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\NeverDeleted;
use App\Models\Concerns\TrashScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockCountItem extends Model
{
    use HasPublicUuid, NeverDeleted;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['system_qty' => 'decimal:3', 'counted_qty' => 'decimal:3', 'unit_cost' => 'decimal:4'];
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function stockable(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes([TrashScope::class]);
    }

    /** counted − system (null until counted). */
    public function variance(): ?float
    {
        return $this->counted_qty === null ? null : round((float) $this->counted_qty - (float) $this->system_qty, 3);
    }
}
