<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LoanProduct;
use App\Models\OrganizationProfile;
use App\Models\Samity;
use App\Services\LoanReportService;
use App\Services\PdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Step 20 — the eleven loan registers on the shared report engine. */
class LoanReportController extends Controller
{
    public function __construct(private LoanReportService $reports) {}

    public function index(Request $request): Response
    {
        $key = (string) $request->query('report', 'member_loan_balance');
        abort_unless($this->reports->has($key), 404);
        $definition = $this->reports->registry()[$key];
        $filters = $this->filters($request, $definition['filters']);
        $ran = $request->boolean('run');
        $result = $ran
            ? $this->reports->run($key, $filters)
            : ['columns' => [], 'rows' => [], 'total_row' => null];

        return Inertia::render('Reports/LoanReports', [
            'reportKey' => $key,
            'reports' => collect($this->reports->registry())
                ->map(fn ($def, $k) => ['key' => $k, 'title' => $def['title'], 'filters' => $def['filters']])
                ->values()->all(),
            'activeFilters' => $definition['filters'],
            'filters' => $filters,
            'columns' => $result['columns'],
            'rows' => $result['rows'],
            'totalRow' => $result['total_row'],
            'ran' => $ran,
            'samities' => Samity::orderBy('name')->get(['id', 'samity_code', 'name'])->all(),
            'employees' => Employee::orderBy('name')->get(['id', 'name', 'employee_code'])->all(),
            'products' => LoanProduct::orderBy('code')->get(['id', 'code', 'name_en'])->all(),
            'frequencies' => ['D', 'W', 'M', 'Q', 'H', 'Y', 'F-15days'],
            'loanTypes' => ['all', 'installment_receivable', 'overdue_only', 'receive_only', 'expire', 'closed', 'active'],
            'loanStatuses' => ['Pending', 'Approved', 'Opened', 'Disbursed', 'Active', 'Closed', 'WrittenOff'],
        ]);
    }

    public function pdf(Request $request, string $key, PdfService $pdf): HttpResponse
    {
        abort_unless($this->reports->has($key), 404);
        $definition = $this->reports->registry()[$key];
        $filters = $this->filters($request, $definition['filters']);
        $result = $this->reports->run($key, $filters);
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

    private function filters(Request $request, array $allowed): array
    {
        $out = [];
        foreach ($allowed as $name) {
            $out[$name] = $request->query($name);
        }

        return $out;
    }
}
