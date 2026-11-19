<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\OrganizationProfile;
use App\Services\BranchAccessService;
use App\Services\BusinessDayService;
use App\Services\PdfService;
use App\Services\RegisterReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Client round 3 (2026-11-18) — the paper registers on screen + PDF:
 * the manager's দৈনিক অফিস তথ্য রেজিস্ট্রার (Part A), the সাপ্তাহিক
 * স্থিতির হিসাবের সিট (Part B), and the Director-only শাখা সমূহের
 * রেজিস্টার list (Part C).
 *
 * Gates: the register pages open with reports.view OR director.view
 * (the Director role holds ONLY director.view — the same treatment
 * /director/pdf gets); printing takes reports.print OR director.view;
 * the all-branches list is director.view only (route middleware).
 *
 * Branch picker: the Director (director.view) and Super Admin pick
 * ANY active branch; everyone else is limited to their visible
 * branches (BranchAccessService) and defaults to their own branch.
 */
class RegisterController extends Controller
{
    public function __construct(
        private RegisterReportService $registers,
        private BranchAccessService $branchAccess,
        private BusinessDayService $businessDays,
    ) {}

    // --- Part A: daily register -------------------------------------------

    public function managerRegister(Request $request): Response
    {
        $this->authorizeViewer($request);
        $branch = $this->resolveBranch($request);
        $month = $this->resolveMonth($request, $branch);
        $data = $this->registers->dailyRegister($branch->id, $month);

        return Inertia::render('Reports/ManagerRegister', [
            'branches' => $this->branchOptions($request),
            'branch' => $this->branchPayload($branch),
            'month' => $month,
            'weeks' => $data['weeks'],
            'grand' => $data['grand'],
        ]);
    }

    public function managerRegisterPdf(Request $request, PdfService $pdf): HttpResponse
    {
        $this->authorizePrinter($request);
        $branch = $this->resolveBranch($request);
        $month = $this->resolveMonth($request, $branch);
        $data = $this->registers->dailyRegister($branch->id, $month);
        $html = view('reports.manager-register', [
            'org' => $this->org(),
            'branch' => $branch,
            'month' => $month,
            'weeks' => $data['weeks'],
            'grand' => $data['grand'],
        ])->render();
        $rowCount = collect($data['weeks'])->sum(fn ($w) => count($w['rows']));
        // The paper register is a landscape sheet (25 columns) — the
        // mPDF path needs that geometry explicitly; Chromium reads it
        // from the blade's @page rule instead.
        $bytes = $pdf->renderHtmlWithFallback($html, $rowCount, 'A4', 'L');

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="daily-office-register-'.$branch->code.'-'.$month.'.pdf"',
        ]);
    }

    // --- Part B: weekly balance sheet --------------------------------------

    public function weeklyBalance(Request $request): Response
    {
        $this->authorizeViewer($request);
        $branch = $this->resolveBranch($request);
        $end = $this->resolveWeekEnd($request, $branch);
        $data = $this->registers->weeklyBalance($branch->id, $end);

        return Inertia::render('Reports/WeeklyBalance', [
            'branches' => $this->branchOptions($request),
            'branch' => $this->branchPayload($branch),
            'weekEnd' => $end,
            'week' => $data['week'],
            'savings' => $data['savings'],
            'savingsTotal' => $data['savings_total'],
            'loans' => $data['loans'],
            'loansTotal' => $data['loans_total'],
        ]);
    }

    public function weeklyBalancePdf(Request $request, PdfService $pdf): HttpResponse
    {
        $this->authorizePrinter($request);
        $branch = $this->resolveBranch($request);
        $end = $this->resolveWeekEnd($request, $branch);
        $data = $this->registers->weeklyBalance($branch->id, $end);
        $html = view('reports.weekly-balance', [
            'org' => $this->org(),
            'branch' => $branch,
            'week' => $data['week'],
            'savings' => $data['savings'],
            'savingsTotal' => $data['savings_total'],
            'loans' => $data['loans'],
            'loansTotal' => $data['loans_total'],
        ])->render();
        $bytes = $pdf->renderHtmlWithFallback($html, count($data['savings']) + count($data['loans']));

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="weekly-balance-'.$branch->code.'.pdf"',
        ]);
    }

    // --- Part C: all-branches register list (Director only) -----------------

    public function branchRegisters(Request $request): Response
    {
        $branches = Branch::where('status', 'Active')->orderBy('code')->get()
            ->map(fn (Branch $b) => [
                'id' => $b->id, 'code' => $b->code, 'name' => $b->name,
                'business_date' => $this->businessDays->currentDate($b->id),
            ])->all();

        return Inertia::render('Reports/BranchRegisters', ['branches' => $branches]);
    }

    // --- helpers -------------------------------------------------------------

    private function authorizeViewer(Request $request): void
    {
        abort_unless($request->user()->canAny(['reports.view', 'director.view']), 403);
    }

    private function authorizePrinter(Request $request): void
    {
        abort_unless($request->user()->canAny(['reports.print', 'director.view']), 403);
    }

    /** @return array<int, array{id: int, code: string, name: string}> */
    private function branchOptions(Request $request): array
    {
        $user = $request->user();
        $branches = ($user->can('director.view') || $user->hasRole('super-admin'))
            ? Branch::where('status', 'Active')->orderBy('code')->get()
            : $this->branchAccess->visibleBranches($user);

        return $branches->map(fn (Branch $b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name])->all();
    }

    private function resolveBranch(Request $request): Branch
    {
        $options = collect($this->branchOptions($request));
        abort_if($options->isEmpty(), 403);
        $requested = $request->integer('branch_id');
        if ($requested) {
            abort_unless($options->contains('id', $requested), 403);

            return Branch::findOrFail($requested);
        }
        $active = (int) ($request->session()->get('active_branch_id') ?? 0);
        $default = $options->firstWhere('id', $active) ?? $options->first();

        return Branch::findOrFail($default['id']);
    }

    private function resolveMonth(Request $request, Branch $branch): string
    {
        $month = (string) $request->query('month', '');
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $month = substr($this->businessDays->currentDate($branch->id), 0, 7);
        }

        return $month;
    }

    private function resolveWeekEnd(Request $request, Branch $branch): string
    {
        $end = (string) $request->query('week_end', '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) !== 1) {
            $end = $this->businessDays->currentDate($branch->id);
        }

        return $end;
    }

    /** @return array{id: int, code: string, name: string} */
    private function branchPayload(Branch $branch): array
    {
        return ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name];
    }

    private function org(): ?OrganizationProfile
    {
        return OrganizationProfile::where('is_active', true)->first() ?? OrganizationProfile::first();
    }
}
