<?php

namespace App\Http\Controllers;

use App\Services\BranchAccessService;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 2 / Step 24 — the post-login home IS the dashboard: KPI cards,
 * charts and officer/samity summary tables computed by DashboardService
 * (branch-scoped in the data layer), with the mega-hub below it.
 *
 * Query param `branch_id` narrows every figure to one branch; it must be
 * one of the user's accessible branches (same rule as the branch switch).
 */
class DashboardController extends Controller
{
    public function index(Request $request, DashboardService $dashboard, BranchAccessService $access): Response
    {
        $user = $request->user();
        $branchId = $request->query('branch_id') !== null ? (int) $request->query('branch_id') : null;
        if ($branchId !== null) {
            abort_unless($user && $access->canAccess($user, $branchId), 403);
        }

        $data = $dashboard->build($user, $branchId, $request->attributes->get('business_date'));

        return Inertia::render('Dashboard', [
            ...$data,
            'branches' => $access->visibleBranches($user)
                ->map(fn ($b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name])
                ->values()->all(),
            'selectedBranch' => $branchId,
        ]);
    }
}
