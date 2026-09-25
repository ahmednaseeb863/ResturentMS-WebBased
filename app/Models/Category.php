<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasBranchSlug;
use App\Models\Concerns\HasImage;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** POS category, shared by menu items and ready items (e.g. "Drinks" holds ready items). */
class Category extends Model
{
    use BelongsToBranch, HasBranchSlug, HasFactory, HasImage, HasPublicUuid, LogsActivity, Trashable;

    protected $fillable = ['name', 'image', 'kitchen_station_id', 'sort_order', 'is_active'];

    protected array $trashParents = ['kitchenStation'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class)->withTrashed();
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

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    public function canBeTrashed(): true|string
    {
        $uses = array_filter([
            'menu items' => $this->menuItems()->count(),
            'ready items' => $this->readyItems()->count(),
        ]);

        return $uses
            ? 'It still has '.collect($uses)->map(fn ($n, $noun) => "{$n} {$noun}")->join(' and ').' — move or trash them first.'
            : true;
    }
}
