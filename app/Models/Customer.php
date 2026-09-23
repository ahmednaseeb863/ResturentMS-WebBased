<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A customer (`users` table), shared by every branch. Phone is the main POS
 * lookup. Password is optional — reserved for the future customer app (`web` guard).
 */
class Customer extends Authenticatable
{
    use HasFactory, HasPublicUuid, LogsActivity, Trashable;

    protected $table = 'users';

    protected $fillable = ['name', 'phone', 'email', 'birthday', 'notes'];

    protected $hidden = ['password', 'remember_token'];

    protected array $trashCascade = ['addresses'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'birthday' => 'date',
            'total_spent' => 'decimal:2',
            'visits_count' => 'integer',
            'last_visit_at' => 'datetime',
        ];
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class, 'user_id')->orderByDesc('is_default')->orderBy('id');
    }

    /** "+92 300 1234567" / "0300-1234567" → "+923001234567" / "03001234567" (one format for lookups). */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $phone = trim($phone);

        return (str_starts_with($phone, '+') ? '+' : '').preg_replace('/\D/', '', $phone);
    }

    public function initials(): string
    {
        return collect(explode(' ', trim($this->name)))->filter()->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    }
}
