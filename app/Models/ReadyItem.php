<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasImage;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\SoldInDeals;
use App\Models\Concerns\Stockable;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sold as-is with stock kept (soft drinks, water, packaged desserts). */
class ReadyItem extends Model
{
    use BelongsToBranch, HasFactory, HasImage, HasPublicUuid, LogsActivity, SoldInDeals, Stockable, Trashable;

    protected $fillable = [
        'category_id', 'kitchen_station_id', 'code', 'barcode', 'name', 'image', 'price',
        'stock_unit_id', 'purchase_unit_id', 'purchase_unit_factor', 'alert_level',
        'available_for', 'is_active', 'sort_order',
    ];

    protected array $activityHidden = ['current_stock', 'avg_cost'];

    protected array $trashParents = ['category'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'available_for' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class)->withTrashed();
    }

    public function canBeTrashed(): true|string
    {
        return $this->dealUsageReason() ?? true;
    }
}
