<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\HasRecipe;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Size / variant with its own price, and optionally its own recipe (Large uses more cheese). */
class MenuItemVariant extends Model
{
    use HasPublicUuid, HasRecipe, Trashable;

    protected $fillable = ['menu_item_id', 'name', 'price', 'is_default', 'sort_order'];

    /** Edited inside the menu item form; the item logs one summary. */
    protected bool $logTrashActivity = false;

    protected array $trashCascade = ['recipeItems'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_default' => 'boolean', 'sort_order' => 'integer'];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class)->withTrashed();
    }

    /** Deal options that fix this size ("Zinger — Large"). */
    public function dealOptions(): HasMany
    {
        return $this->hasMany(DealSlotOption::class, 'variant_id');
    }

    public function recipeLabel(): string
    {
        return $this->loadMissing('menuItem')->menuItem->name.' — '.$this->name;
    }

    public function trashLabel(): string
    {
        return $this->name;
    }
}
