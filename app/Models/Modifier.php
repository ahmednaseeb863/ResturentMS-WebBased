<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\HasRecipe;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One add-on of a modifier group, with an optional recipe (Extra cheese → cheese 30 g). */
class Modifier extends Model
{
    use HasPublicUuid, HasRecipe, Trashable;

    protected $fillable = ['modifier_group_id', 'name', 'price', 'is_active', 'sort_order'];

    /** Edited inside the group form; the group logs one summary. */
    protected bool $logTrashActivity = false;

    protected array $trashCascade = ['recipeItems'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id')->withTrashed();
    }

    public function recipeLabel(): string
    {
        return $this->name.' (add-on)';
    }
}
