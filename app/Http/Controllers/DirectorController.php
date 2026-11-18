<?php

namespace App\Http\Controllers;

use App\Models\OrganizationProfile;
use App\Services\DirectorDashboardService;
use App\Services\PdfService;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Director Dashboard (client order, 2026-11-14) — the পরিচালক's
 * view-only page: consolidated today / this-month / overall figures
 * for all branches plus a per-branch table. No actions, no forms.
 */
class DirectorController extends Controller
{
    public function index(DirectorDashboardService $director): Response
    {
        return Inertia::render('Director/Dashboard', $director->build());
    }

    /**
     * Print / save-as-PDF of exactly what the Director page shows
     * (client order 2026-11-18): same figures from the same service,
     * combined and branch-wise, through the shared PdfService.
     */
    public function pdf(DirectorDashboardService $director, PdfService $pdf): HttpResponse
    {
        $org = OrganizationProfile::where('is_active', true)->first() ?? OrganizationProfile::first();
        $html = view('reports.director', [
            'org' => $org,
            ...$director->build(),
        ])->render();
        $bytes = $pdf->renderHtmlWithFallback($html, 0);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="director-dashboard.pdf"',
        ]);
    }
}
