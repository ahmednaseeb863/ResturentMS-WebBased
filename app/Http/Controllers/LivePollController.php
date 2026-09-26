<?php

namespace App\Http\Controllers;

use App\Support\CurrentBranch;
use App\Support\LiveUpdates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The one request screens repeat every few seconds (no WebSockets): the change stamps of
 * the current branch's topics, and the "order ready" events since the last poll. Screens
 * reload their own data only when a stamp moved. Every signed-in admin may poll; ready
 * events only go to those who use the POS / orders.
 */
class LivePollController extends Controller
{
    public function __invoke(Request $request, CurrentBranch $current): JsonResponse
    {
        $branch = $current->id();
        if (! $branch) {
            return response()->json(['stamp' => null, 'versions' => [], 'events' => []]);
        }

        $topics = array_values(array_intersect(LiveUpdates::TOPICS, (array) $request->query('topics', [])));
        $since = $request->filled('since') ? (int) $request->query('since') : null;

        $admin = $request->user('admin');
        if (! $admin->canRoute('pos.index') && ! $admin->canRoute('orders.index')) {
            $topics = array_values(array_diff($topics, ['orders']));
        }

        return response()->json(LiveUpdates::poll($branch, $topics, $since));
    }
}
