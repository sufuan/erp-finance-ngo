<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Services\BranchAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active branch for the request (plan G3): the branch
 * switcher stores it in the session; all transactional queries scope to
 * it. Step 2 restricts resolution to the user's accessible branches —
 * Super Admin sees all Active branches; everyone else only branch_user
 * assignments (include_children rolls down the tree); guests get none.
 */
class SetActiveBranch
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Schema::hasTable('branches')) {
            $access = app(BranchAccessService::class);
            $visible = $access->visibleBranches($request->user());
            $branch = null;
            $sessionId = $request->session()->get('active_branch_id');

            if ($sessionId) {
                $branch = $visible->firstWhere('id', (int) $sessionId);
            }
            $branch ??= $visible->sortBy(fn (Branch $b) => [$b->type === 'Head Office' ? 0 : 1, $b->code])->first();

            if ($branch) {
                $request->session()->put('active_branch_id', $branch->id);
                $request->attributes->set('active_branch', $branch);
                app()->instance('active_branch', $branch);
            } else {
                $request->session()->forget('active_branch_id');
            }
        }

        return $next($request);
    }

    public static function current(Request $request): ?Branch
    {
        if ($branch = $request->attributes->get('active_branch')) {
            return $branch;
        }

        return app()->bound('active_branch') ? app('active_branch') : null;
    }
}
