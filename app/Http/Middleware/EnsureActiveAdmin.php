<?php

namespace App\Http\Middleware;

use App\Support\CurrentBranch;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivating an account, or removing all its branches, takes effect at once —
 * not at the next login. (A trashed account is already signed out: the guard
 * cannot load it any more.)
 */
class EnsureActiveAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if (! $admin || $request->routeIs('logout')) {
            return $next($request);
        }

        $reason = match (true) {
            ! $admin->is_active => 'Your account has been deactivated. Please contact the administrator.',
            ! $admin->is_super_admin && app(CurrentBranch::class)->available()->isEmpty() => 'Your account has no branch access. Please contact the administrator.',
            default => null,
        };

        if ($reason === null) {
            return $next($request);
        }

        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('error', $reason);
    }
}
