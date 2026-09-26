<?php

namespace App\Models;

use App\Enums\PrinterConnection;
use App\Enums\PrinterType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A thermal printer of the branch (receipt or kitchen). Printing itself runs in the browser. */
class Printer extends Model
{
    use BelongsToBranch, HasFactory, HasPublicUuid, LogsActivity, Trashable;

    public const DEFAULT_PORT = 9100;

    protected $fillable = ['name', 'type', 'connection_type', 'device_name', 'ip_address', 'port', 'paper_width', 'is_active'];

    protected array $activityHidden = ['last_tested_at'];

    protected function casts(): array
    {
        return [
            'type' => PrinterType::class,
            'connection_type' => PrinterConnection::class,
            'port' => 'integer',
            'paper_width' => 'integer',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    /** Cash counters printing their receipts here. */
    public function counters(): HasMany
    {
        return $this->hasMany(CashCounter::class, 'receipt_printer_id');
    }

    /** Kitchen stations printing their tickets (KOT) here. */
    public function kitchenStations(): HasMany
    {
        return $this->hasMany(KitchenStation::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, PrinterType $type): void
    {
        $query->where('type', $type);
    }

    /** Active printers of the branch for "this screen prints for…" pickers. */
    public static function deviceOptions(): array
    {
        return static::query()->active()->orderBy('name')->get()
            ->map(fn (self $p) => ['id' => $p->uuid, 'name' => $p->name, 'type' => $p->type->label()])->all();
    }

    /** OS printer name (USB) or "IP:port" (network). */
    public function address(): string
    {
        return $this->connection_type === PrinterConnection::Network
            ? "{$this->ip_address}:".($this->port ?: self::DEFAULT_PORT)
            : (string) $this->device_name;
    }

    public function canBeTrashed(): true|string
    {
        $users = $this->counters()->pluck('name')->merge($this->kitchenStations()->pluck('name'));

        return $users->isNotEmpty()
            ? 'Used by '.$users->map(fn ($n) => "“{$n}”")->join(', ').' — pick another printer there first.'
            : true;
    }
}
