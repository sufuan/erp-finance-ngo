<?php

namespace App\Http\Controllers;

use App\Services\BranchAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Stores the chosen branch in the session (restricted to accessible ones, Step 2). */
class BranchSwitchController extends Controller
{
    public function switch(Request $request, BranchAccessService $access): RedirectResponse
    {
        $branchId = (int) $request->input('branch_id');
        abort_unless($request->user() && $access->canAccess($request->user(), $branchId), 403);
        $request->session()->put('active_branch_id', $branchId);

        return back();
    }
}
