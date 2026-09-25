<?php

namespace App\Models;

use App\Models\Concerns\Trashable;
use App\Support\Qty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** "One serving uses 150 g of chicken" — a line of a menu item / variant / modifier recipe. */
class RecipeItem extends Model
{
    use Trashable;

    protected $fillable = ['raw_material_id', 'quantity', 'unit_id', 'sort_order'];

    /** Edited inside the owner's form; the owner logs one summary. */
    protected bool $logTrashActivity = false;

    protected array $trashParents = ['rawMaterial'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'sort_order' => 'integer'];
    }

    public function recipeable(): MorphTo
    {
        return $this->morphTo();
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }

    /** "Chicken 150 g" */
    public function describe(): string
    {
        return trim(($this->rawMaterial?->name ?? '').' '.Qty::format($this->quantity).' '.$this->unit?->short_name);
    }
}
