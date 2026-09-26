<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Branch;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Manager PIN approval (settings group `approvals`). The approver is any active admin of
 * the branch, with a PIN, who may use `$route` — e.g. `shifts.reopen` for shift approvals.
 * A manager approving their own action still enters their PIN (proves they are at the till).
 *
 *   $approver = ManagerApproval::verify($request->input('pin'), 'shifts.reopen', $branch);
 */
class ManagerApproval
{
    public const MAX_ATTEMPTS = 5;

    /** @throws ValidationException (key `pin`) when the PIN is missing, wrong or rate-limited */
    public static function verify(?string $pin, string $route, Branch $branch, string $field = 'pin'): Admin
    {
        $key = 'approval:'.(auth('admin')->id() ?? request()->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                $field => 'Too many wrong PINs. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        if ($pin === null || $pin === '') {
            throw ValidationException::withMessages([$field => 'A manager must enter their PIN.']);
        }

        $approver = static::candidates($route, $branch)->first(fn (Admin $admin) => $admin->checkPin($pin));

        if (! $approver) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([$field => 'PIN not accepted — it must be the PIN of a manager of this branch.']);
        }

        RateLimiter::clear($key);

        return $approver;
    }

    /** Active admins of the branch with a PIN who may use the route. */
    public static function candidates(string $route, Branch $branch)
    {
        return Admin::query()
            ->where('is_active', true)
            ->whereNotNull('pin')
            ->with('role')
            ->get()
            ->filter(fn (Admin $admin) => $admin->canRoute($route) && $admin->canAccessBranch($branch))
            ->values();
    }
}
