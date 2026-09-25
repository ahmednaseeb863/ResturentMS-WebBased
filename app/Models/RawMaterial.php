<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Stockable;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kitchen material (chicken, flour, buns, packaging). Not sold; used through recipes. */
class RawMaterial extends Model
{
    use BelongsToBranch, HasFactory, HasPublicUuid, LogsActivity, Stockable, Trashable;

    protected $fillable = ['category_id', 'code', 'name', 'stock_unit_id', 'purchase_unit_id', 'purchase_unit_factor', 'alert_level', 'is_active'];

    /** Stock and cost change through the ledger, which is the history for them. */
    protected array $activityHidden = ['current_stock', 'avg_cost'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RawMaterialCategory::class, 'category_id')->withTrashed();
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    /** Names of live menu items / variants / modifiers whose recipe uses it. */
    public function usedIn(): array
    {
        return $this->recipeItems()->with('recipeable')->get()
            ->map(fn (RecipeItem $line) => $line->recipeable)
            ->filter()
            ->map(fn (Model $owner) => $owner->recipeLabel())
            ->unique()->values()->all();
    }

    public function canBeTrashed(): true|string
    {
        $uses = $this->usedIn();

        if (! $uses) {
            return true;
        }

        $list = collect($uses)->take(3)->map(fn ($n) => "“{$n}”")->join(', ');
        $more = count($uses) > 3 ? ' and '.(count($uses) - 3).' more' : '';

        return "Used in the recipe of {$list}{$more} — remove it there first.";
    }
}
