<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Grill, Fryer, Drinks… Items sent to the kitchen go to their station's screen and/or printer. */
class KitchenStation extends Model
{
    use BelongsToBranch, HasFactory, HasPublicUuid, LogsActivity, Trashable;

    protected $fillable = ['name', 'has_screen', 'printer_id', 'is_active'];

    protected array $trashParents = ['printer'];

    protected function casts(): array
    {
        return ['has_screen' => 'boolean', 'is_active' => 'boolean'];
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class)->withTrashed();
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    public function readyItems(): HasMany
    {
        return $this->hasMany(ReadyItem::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function canBeTrashed(): true|string
    {
        $uses = array_filter([
            'categories' => $this->categories()->count(),
            'menu items' => $this->menuItems()->count(),
            'ready items' => $this->readyItems()->count(),
        ]);

        return $uses
            ? 'Used by '.collect($uses)->map(fn ($n, $noun) => "{$n} {$noun}")->join(', ').' — move them to another station first.'
            : true;
    }
}
