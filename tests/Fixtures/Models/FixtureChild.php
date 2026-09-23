<?php

namespace Tests\Fixtures\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixtureChild extends Model
{
    use HasPublicUuid, Trashable;

    protected $guarded = [];

    protected array $trashParents = ['parent'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(FixtureParent::class, 'fixture_parent_id');
    }
}
