<?php

namespace App\Http\Controllers\Collections;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OrganizationProfile;
use App\Models\Samity;
use App\Services\BranchAccessService;
use App\Services\BusinessDayService;
use App\Services\CollectionReportService;
use App\Services\PdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Steps 22–23 — collection sheets + reports on the shared registry
 * engine. Sheets are gated by collection.sheets, reports by
 * collection.reports, PDFs by collection.print. Every query is scoped
 * to the branches the caller may access.
 */
class CollectionReportController extends Controller
{
    public function __construct(
        private CollectionReportService $reports,
        private BranchAccessService $branches,
        private BusinessDayService $businessDays,
    ) {}

    public function sheets(Request $request): Response
    {
        return $this->page($request, 'sheets', 'Collections/Sheets');
    }

    public function reports(Request $request): Response
    {
        return $this->page($request, 'reports', 'Collections/Reports');
    }

    public function balances(Request $request): Response
    {
        return $this->page($request, 'balance', 'Collections/Reports');
    }

    private function page(Request $request, string $group, string $component): Response
    {
        $registry = collect($this->reports->registry())
            ->filter(fn ($def) => $group === 'reports'
                ? in_array($def['group'], ['reports', 'performance', 'branch', 'pomis'], true)
                : $def['group'] === $group);
        $default = $registry->keys()->first();
        $key = (string) $request->query('report', $default);
        abort_unless($registry->has($key), 404);
        $definition = $registry[$key];
        $filters = $this->filters($request, $definition['filters']);
        $ran = $request->boolean('run');
        $result = $ran
            ? $this->reports->run($key, [...$filters, ...$this->internals($request)])
            : ['columns' => [], 'rows' => [], 'total_row' => null];

        return Inertia::render($component, [
            'reportKey' => $key,
            'group' => $group,
            'reports' => $registry
                ->map(fn ($def, $k) => ['key' => $k, 'title' => $def['title'], 'group' => $def['group'], 'filters' => $def['filters']])
                ->values()->all(),
            'activeFilters' => $definition['filters'],
            'filters' => $filters,
            'columns' => $result['columns'],
            'rows' => $result['rows'],
            'totalRow' => $result['total_row'],
            'ran' => $ran,
            'samities' => Samity::whereIn('branch_id', $this->branches->accessibleIds($request->user()))
                ->orderBy('name')->get(['id', 'samity_code', 'name'])->all(),
            'employees' => Employee::orderBy('name')->get(['id', 'name', 'employee_code'])->all(),
        ]);
    }

    public function pdf(Request $request, string $key, PdfService $pdf): HttpResponse
    {
        abort_unless($this->reports->has($key), 404);
        $definition = $this->reports->registry()[$key];
        $filters = $this->filters($request, $definition['filters']);
        $result = $this->reports->run($key, [...$filters, ...$this->internals($request)]);
        $org = OrganizationProfile::where('is_active', true)->first() ?? OrganizationProfile::first();
        $summary = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')
            ->map(fn ($v, $k) => $k.': '.$v)->implode(' · ');
        $html = view($definition['pdf_view'], [
            'org' => $org,
            'title' => __($definition['title']),
            'filter_summary' => $summary,
            'columns' => collect($result['columns'])->map(fn ($c) => __($c))->all(),
            'rows' => $result['rows'],
            'total_row' => $result['total_row'] ? collect($result['total_row'])
                ->map(fn ($c, $i) => $i === 0 && $c !== '' ? __('total') : $c)->all() : null,
        ])->render();
        $bytes = $pdf->renderHtmlWithFallback($html, count($result['rows']));

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$key.'.pdf"',
        ]);
    }

    private function internals(Request $request): array
    {
        $active = $request->attributes->get('active_branch');
        $businessDate = null;
        if ($active) {
            try {
                $businessDate = $this->businessDays->currentDate($active->id);
            } catch (\Throwable) {
                $businessDate = null;
            }
        }

        return [
            '_branch_ids' => $this->branches->accessibleIds($request->user()),
            '_business_date' => $businessDate,
        ];
    }

    private function filters(Request $request, array $allowed): array
    {
        $out = [];
        foreach ($allowed as $name) {
            $out[$name] = $request->query($name);
        }

        return $out;
    }
}
