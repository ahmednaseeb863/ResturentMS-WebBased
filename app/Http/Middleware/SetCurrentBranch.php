<?php

namespace App\Http\Middleware;

use App\Support\CurrentBranch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the branch the signed-in admin works in (session → else their first
 * allowed branch) and switches on the BelongsToBranch scope for the request.
 * Runs in the `web` group before HandleInertiaRequests so the shell props see it.
 */
class SetCurrentBranch
{
    public function __construct(private CurrentBranch $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if ($admin) {
            $branches = $admin->accessibleBranches();
            $id = $request->session()->get(CurrentBranch::SESSION_KEY);
            $branch = $branches->firstWhere('id', $id) ?? $branches->first();

            if ($branch?->id !== $id) {
                $request->session()->put(CurrentBranch::SESSION_KEY, $branch?->id);
            }

            $this->current->set($branch, $branches);
            $this->current->enforce();
        }

        return $next($request);
    }
}
