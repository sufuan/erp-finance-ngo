<?php

namespace App\Http\Controllers;

use App\Services\DirectorDashboardService;
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
}
