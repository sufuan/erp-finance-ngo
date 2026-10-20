<?php

namespace App\Http\Middleware;

use App\Services\BusinessDayService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes the active branch's current open business date to the request
 * (plan Step 1). Postings must use this date — never the wall clock.
 */
class SetBusinessDate
{
    public function handle(Request $request, Closure $next): Response
    {
        $branch = SetActiveBranch::current($request);

        if ($branch && Schema::hasTable('business_days')) {
            $date = app(BusinessDayService::class)->currentDate($branch->id);
            $request->attributes->set('business_date', $date);
            app()->instance('business_date', $date);
        }

        return $next($request);
    }
}
