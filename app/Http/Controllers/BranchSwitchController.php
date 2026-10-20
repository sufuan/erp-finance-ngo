<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BranchSwitchController extends Controller
{
    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        $branch = Branch::active()->findOrFail($validated['branch_id']);
        $request->session()->put('active_branch_id', $branch->id);

        return back();
    }
}
