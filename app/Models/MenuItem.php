<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasBranchSlug;
use App\Models\Concerns\HasImage;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\HasRecipe;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\SoldInDeals;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Prepared in the kitchen, **no stock** — only sales are tracked; its recipe consumes
 * raw materials. A variant's own recipe replaces the item's; modifier recipes add on top.
 */
class MenuItem extends Model
{
    use BelongsToBranch, HasBranchSlug, HasFactory, HasImage, HasPublicUuid, HasRecipe, LogsActivity, SoldInDeals, Trashable;

    protected $fillable = [
        'category_id', 'kitchen_station_id', 'name', 'description', 'image', 'price',
        'prep_time_minutes', 'available_for', 'is_active', 'is_sold_out', 'sort_order',
    ];

    protected array $trashCascade = ['variants', 'recipeItems'];

    protected array $trashParents = ['category'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'prep_time_minutes' => 'integer',
            'available_for' => 'array',
            'is_active' => 'boolean',
            'is_sold_out' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    /** Own station; when empty the category's station is used. */
    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class)->withTrashed();
    }

    public function variants(): HasMany
    {
        return $this->hasMany(MenuItemVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class)
            ->using(MenuItemModifierGroup::class)
            ->withPivot('sort_order')
            ->wherePivotNull('deleted_at')
            ->orderByPivot('sort_order');
    }

    public function recipeLabel(): string
    {
        return $this->name;
    }

    public function canBeTrashed(): true|string
    {
        return $this->dealUsageReason() ?? true;
    }
}
