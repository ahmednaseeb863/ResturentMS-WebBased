<?php

namespace App\Http\Controllers;

use App\Support\BranchPicker;
use App\Support\DashboardStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard (PLAN §4.19). Everyone may open it; the figures (sales, cash, stock…) need the
 * `dashboard.stats` permission. The page asks `dashboard.stats` again every minute (polling,
 * no WebSockets) for one branch or all branches the admin works in.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $admin = $request->user('admin');
        $canStats = $admin->canRoute('dashboard.stats');
        $branches = BranchPicker::branches($request);

        return Inertia::render('Dashboard', [
            'stats' => $canStats && $branches->isNotEmpty() ? (new DashboardStats($branches))->toArray() : null,
            'branch' => BranchPicker::selected($branches),
            'branchLabel' => $branches->count() > 1 ? 'All branches' : $branches->first()?->name,
            'branchOptions' => $canStats ? BranchPicker::options($admin) : [],
            'refreshSeconds' => 60,
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $branches = BranchPicker::branches($request);
        abort_if($branches->isEmpty(), 403);

        return response()->json((new DashboardStats($branches))->toArray());
    }
}
