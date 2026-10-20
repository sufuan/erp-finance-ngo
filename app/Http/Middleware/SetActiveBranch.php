<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active branch for the request (plan G3): the branch
 * switcher stores it in the session; all transactional queries scope to
 * it. Access restriction to a user's assigned branches is enforced from
 * Step 2 (branch_user) — Super Admin sees all Active branches.
 */
class SetActiveBranch
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Schema::hasTable('branches')) {
            $branch = null;
            $sessionId = $request->session()->get('active_branch_id');

            if ($sessionId) {
                $branch = Branch::active()->find($sessionId);
            }
            $branch ??= Branch::active()->orderByRaw("type = 'Head Office' DESC")->orderBy('code')->first();

            if ($branch) {
                $request->session()->put('active_branch_id', $branch->id);
                $request->attributes->set('active_branch', $branch);
                app()->instance('active_branch', $branch);
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
