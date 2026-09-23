<?php

namespace App\Http\Requests\Auth;

use App\Models\Admin;
use App\Support\Activity;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin sign-in by password (username or email) or by PIN (username + PIN) for
 * shared POS / kitchen / waiter devices. Rate-limited per username + IP.
 */
class LoginRequest extends FormRequest
{
    public const MAX_ATTEMPTS = 5;

    public function rules(): array
    {
        return $this->usesPin()
            ? ['username' => ['required', 'string', 'max:150'], 'pin' => ['required', 'digits_between:4,6']]
            : ['username' => ['required', 'string', 'max:150'], 'password' => ['required', 'string'], 'remember' => ['boolean']];
    }

    public function usesPin(): bool
    {
        return $this->routeIs('login.pin');
    }

    public function authenticate(): Admin
    {
        $this->ensureIsNotRateLimited();

        $login = trim((string) $this->input('username'));
        $admin = Admin::query()
            ->where(fn ($q) => $q->where('username', $login)->orWhere('email', $login))
            ->first();

        $valid = $admin && ($this->usesPin()
            ? $admin->checkPin((string) $this->input('pin'))
            : Hash::check((string) $this->input('password'), $admin->password));

        if (! $valid) {
            RateLimiter::hit($this->throttleKey(), 60);

            throw ValidationException::withMessages([
                'username' => $this->usesPin() ? 'Username or PIN is incorrect.' : 'Username or password is incorrect.',
            ]);
        }

        $blocked = match (true) {
            ! $admin->is_active => 'This account is inactive. Please contact the administrator.',
            ! $admin->is_super_admin && $admin->accessibleBranches()->isEmpty() => 'This account has no branch access yet. Please contact the administrator.',
            default => null,
        };

        if ($blocked) {
            throw ValidationException::withMessages(['username' => $blocked]);
        }

        RateLimiter::clear($this->throttleKey());

        Auth::guard('admin')->login($admin, ! $this->usesPin() && $this->boolean('remember'));
        $admin->forceFill(['last_login_at' => now()])->saveQuietly();
        Activity::log('login', $admin, ['method' => $this->usesPin() ? 'pin' : 'password']);

        return $admin;
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'username' => "Too many attempts. Try again in {$seconds} seconds.",
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->input('username')).'|'.$this->ip());
    }
}
