<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetActiveBranch;
use App\Services\AuditService;
use App\Services\BusinessDayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BusinessDayController extends Controller
{
    public function index(Request $request, BusinessDayService $days): Response
    {
        $branch = SetActiveBranch::current($request);
        $current = $branch ? $days->current($branch->id) : null;

        return Inertia::render('Settings/BusinessDay/Index', [
            'branch' => $branch ? ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name] : null,
            'currentDate' => $current?->business_date->toDateString(),
            'nextDate' => $current?->business_date->copy()->addDay()->toDateString(),
        ]);
    }

    public function close(Request $request, BusinessDayService $days, AuditService $audit): RedirectResponse
    {
        $branch = SetActiveBranch::current($request);
        if (! $branch) {
            return back()->with('error', __('something_went_wrong'));
        }

        $before = $days->currentDate($branch->id);
        $newDay = $days->close($branch->id, $request->user()?->id);
        $audit->log($request->user()?->id, 'business_day.closed', 'branches', $branch->id,
            ['business_date' => $before], ['business_date' => $newDay->business_date->toDateString()]);

        return back()->with('success', __('saved_successfully'));
    }
}
