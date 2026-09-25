<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Add-ons offered with menu items ("Extra toppings": pick 0–3). */
class ModifierGroup extends Model
{
    use BelongsToBranch, HasFactory, HasPublicUuid, LogsActivity, Trashable;

    protected $fillable = ['name', 'min_select', 'max_select', 'is_active'];

    protected array $trashCascade = ['modifiers'];

    protected function casts(): array
    {
        return ['min_select' => 'integer', 'max_select' => 'integer', 'is_active' => 'boolean'];
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class)->orderBy('sort_order')->orderBy('id');
    }

    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class)
            ->using(MenuItemModifierGroup::class)
            ->wherePivotNull('deleted_at');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isRequired(): bool
    {
        return $this->min_select > 0;
    }

    /** "Required · pick 1", "Optional · up to 3", "Pick 2–4" */
    public function ruleText(): string
    {
        $min = $this->min_select;
        $max = $this->max_select;

        return match (true) {
            $min === 0 && $max === null => 'Optional · any',
            $min === 0 => "Optional · up to {$max}",
            $max === $min => "Required · pick {$min}",
            $max === null => "Required · at least {$min}",
            default => "Pick {$min}–{$max}",
        };
    }
}
