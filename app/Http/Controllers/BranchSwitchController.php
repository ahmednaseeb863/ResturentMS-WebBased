<?php

namespace App\Http\Controllers;

use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Toolbar branch switcher: only branches the admin can access. */
class BranchSwitchController extends Controller
{
    public function __invoke(Request $request, CurrentBranch $current): RedirectResponse
    {
        $data = $request->validate(['branch' => ['required', 'uuid']]);

        $branch = $current->available()->firstWhere('uuid', $data['branch']);
        abort_unless($branch, 403);

        $request->session()->put(CurrentBranch::SESSION_KEY, $branch->id);

        return redirect()->route('dashboard')->with('success', "Switched to {$branch->name}.");
    }
}
