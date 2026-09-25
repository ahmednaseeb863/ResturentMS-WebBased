<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** "Burger" (fixed: one option) or "Drink — choose 1" (several options), × quantity. */
class DealSlot extends Model
{
    use HasPublicUuid, Trashable;

    protected $fillable = ['deal_id', 'name', 'quantity', 'sort_order'];

    /** Edited inside the deal form; the deal logs one summary. */
    protected bool $logTrashActivity = false;

    protected array $trashCascade = ['options'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'sort_order' => 'integer'];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(DealSlotOption::class)->orderBy('sort_order')->orderBy('id');
    }
}
