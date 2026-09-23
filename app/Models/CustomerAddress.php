<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delivery address of a customer. Edited as part of the customer form: removed
 * addresses are trashed (restored together with the customer).
 */
class CustomerAddress extends Model
{
    use HasPublicUuid, Trashable;

    protected $table = 'user_addresses';

    protected $fillable = ['user_id', 'label', 'address', 'area', 'landmark', 'lat', 'lng', 'is_default'];

    /** The customer logs one summary for address changes. */
    protected bool $logTrashActivity = false;

    protected array $trashParents = ['customer'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'user_id')->withTrashed();
    }

    public function trashLabel(): string
    {
        return $this->label ?: $this->address;
    }
}
