<?php

namespace App\Models;

use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Link menu item ↔ modifier group; unlinking trashes the row (TrashablePivot). */
class MenuItemModifierGroup extends Pivot
{
    use Trashable;

    protected $table = 'menu_item_modifier_group';

    public $incrementing = true;

    protected $fillable = ['menu_item_id', 'modifier_group_id', 'sort_order'];

    protected bool $logTrashActivity = false;
}
