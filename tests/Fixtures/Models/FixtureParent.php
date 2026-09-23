<?php

namespace Tests\Fixtures\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixtureParent extends Model
{
    use HasPublicUuid, Trashable;

    protected $guarded = [];

    protected array $trashCascade = ['children'];

    public function children(): HasMany
    {
        return $this->hasMany(FixtureChild::class);
    }

    public function canBeTrashed(): true|string
    {
        return $this->locked ? 'This record is locked.' : true;
    }
}
