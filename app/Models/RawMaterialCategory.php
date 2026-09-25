<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Meat, Dairy, Vegetables, Packaging… (groups raw materials on stock lists). */
class RawMaterialCategory extends Model
{
    use BelongsToBranch, HasFactory, HasPublicUuid, LogsActivity, Trashable;

    protected $fillable = ['name'];

    public function rawMaterials(): HasMany
    {
        return $this->hasMany(RawMaterial::class, 'category_id');
    }

    public function canBeTrashed(): true|string
    {
        $count = $this->rawMaterials()->count();

        return $count ? "{$count} raw material".($count > 1 ? 's are' : ' is').' in this category — move them first.' : true;
    }
}
