<?php

namespace Tests\Fixtures\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Model;

/** fixture_parents seen as per-branch data, for the BelongsToBranch tests. */
class FixtureBranchRecord extends Model
{
    use BelongsToBranch, HasPublicUuid, Trashable;

    protected $table = 'fixture_parents';

    protected $guarded = [];
}
