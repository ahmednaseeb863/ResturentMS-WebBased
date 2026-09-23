<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Staff record of one home branch (`branch_id`). The login is the linked
 * `admins` row (optional); trashing the employee trashes the login with it.
 */
class Employee extends Model
{
    use BelongsToBranch, HasFactory, HasPublicUuid, LogsActivity, Trashable;

    protected $fillable = [
        'admin_id', 'designation_id', 'code', 'name', 'phone', 'cnic', 'address',
        'photo', 'joining_date', 'salary', 'status',
    ];

    protected array $trashCascade = ['admin'];

    protected array $trashParents = ['designation'];

    protected array $activityHidden = ['photo', 'salary'];

    protected function casts(): array
    {
        return [
            'status' => EmployeeStatus::class,
            'joining_date' => 'date',
            'salary' => 'decimal:2',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class)->withTrashed();
    }

    public function isActive(): bool
    {
        return $this->status === EmployeeStatus::Active;
    }

    public function photoUrl(): ?string
    {
        return $this->photo ? Storage::disk('public')->url($this->photo) : null;
    }

    /** Next free code for a branch, e.g. MAIN-0007. */
    public static function nextCode(Branch $branch): string
    {
        $prefix = $branch->code.'-';
        $last = static::query()->allBranches()->withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->map(fn (string $code) => (int) substr($code, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    public function canBeTrashed(): true|string
    {
        $managed = Branch::query()->where('manager_id', $this->getKey())->value('name');

        return $managed
            ? "This employee is the manager of “{$managed}” — allocate another manager first."
            : true;
    }
}
