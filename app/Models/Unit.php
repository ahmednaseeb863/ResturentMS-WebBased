<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * Unit of measure, shared by all branches. A base unit (kg, L, pcs, carton) or part of
 * one: `factor` = base units in one of it (g = 0.001 kg). Units of the same family
 * convert automatically, so a recipe in grams deducts correctly from stock kept in kg.
 */
class Unit extends Model
{
    use HasFactory, HasPublicUuid, LogsActivity, Trashable;

    protected $fillable = ['name', 'short_name', 'base_unit_id', 'factor'];

    protected function casts(): array
    {
        return ['factor' => 'float'];
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_unit_id')->withTrashed();
    }

    public function derivedUnits(): HasMany
    {
        return $this->hasMany(self::class, 'base_unit_id');
    }

    /** Units of one family share this id (the base unit's). */
    public function familyId(): int
    {
        return $this->base_unit_id ?? $this->id;
    }

    public function sameFamily(self $other): bool
    {
        return $this->familyId() === $other->familyId();
    }

    /** 1500 g → 1.5 kg */
    public static function convert(float $quantity, self $from, self $to): float
    {
        if (! $from->sameFamily($to)) {
            throw new InvalidArgumentException("Cannot convert {$from->short_name} to {$to->short_name}.");
        }

        return $quantity * $from->factor / $to->factor;
    }

    /** Raw materials, ready items and recipes using this unit (by name, for messages). */
    public function usage(): array
    {
        $uses = [];

        foreach ([RawMaterial::class => 'raw material', ReadyItem::class => 'ready item'] as $model => $noun) {
            $count = $model::query()->allBranches()
                ->where(fn ($q) => $q->where('stock_unit_id', $this->id)->orWhere('purchase_unit_id', $this->id))
                ->count();
            if ($count) {
                $uses[] = $count.' '.$noun.($count > 1 ? 's' : '');
            }
        }

        $recipes = RecipeItem::query()->where('unit_id', $this->id)->count();
        if ($recipes) {
            $uses[] = $recipes.' recipe line'.($recipes > 1 ? 's' : '');
        }

        return $uses;
    }

    public function canBeTrashed(): true|string
    {
        if ($this->derivedUnits()->exists()) {
            return 'Other units are part of “'.$this->short_name.'” — trash them first.';
        }

        $uses = $this->usage();

        return $uses ? 'Used by '.implode(', ', $uses).'.' : true;
    }

    public function trashLabel(): string
    {
        return "{$this->name} ({$this->short_name})";
    }
}
