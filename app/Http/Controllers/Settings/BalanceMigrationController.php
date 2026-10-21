<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\ImportBalancesJob;
use App\Models\BalanceImportBatch;
use App\Models\Samity;
use App\Services\AuditService;
use App\Services\BalanceImportService;
use App\Services\XlsxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Step 20A — balance migration: dry-run preview → queued import → error report. */
class BalanceMigrationController extends Controller
{
    public function index(?BalanceImportBatch $batch = null): Response
    {
        $batches = BalanceImportBatch::with('creator:id,username')
            ->orderByDesc('id')->limit(20)->get()
            ->map(fn (BalanceImportBatch $b) => [
                'id' => $b->id,
                'created_at' => $b->created_at?->toDateTimeString(),
                'created_by' => $b->creator?->username,
                'source' => $b->source, 'status' => $b->status,
                'total_rows' => $b->total_rows,
                'imported_count' => $b->imported_count,
                'failed_count' => $b->failed_count,
            ])->all();

        $preview = null;
        if ($batch && $batch->exists) {
            $rows = collect($batch->rows ?? []);
            $preview = [
                'id' => $batch->id, 'status' => $batch->status, 'source' => $batch->source,
                'transaction_date' => $batch->transaction_date?->toDateString(),
                'add_members' => (bool) $batch->add_members,
                'total' => $batch->total_rows,
                'ok_count' => $rows->where('errors', [])->count(),
                'error_count' => $rows->filter(fn ($r) => ($r['errors'] ?? []) !== [])->count(),
                'rows' => $rows->map(fn ($r) => [
                    'no' => $r['no'], 'raw' => $r['raw'], 'errors' => $r['errors'] ?? [],
                ])->all(),
                'failures' => $batch->failures ?? [],
            ];
        }

        return Inertia::render('Settings/BalanceMigrations', [
            'samities' => Samity::orderBy('samity_code')->get(['id', 'samity_code', 'name'])
                ->map(fn (Samity $s) => ['id' => $s->id, 'label' => $s->samity_code.' — '.$s->name])->all(),
            'columns' => BalanceImportService::COLUMNS,
            'batches' => $batches,
            'preview' => $preview,
        ]);
    }

    public function dryRunExcel(Request $request, BalanceImportService $service, XlsxService $xlsx): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv', 'max:10240'],
            'samity_id' => ['required', 'integer', 'exists:samities,id'],
            'transaction_date' => ['required', 'date'],
            'add_members' => ['required', 'boolean'],
        ]);
        $grid = $xlsx->read($request->file('file')->getRealPath());
        $headers = $this->headerMap(array_shift($grid) ?? []);
        $rawRows = [];
        foreach ($grid as $line) {
            $raw = [];
            $empty = true;
            foreach ($headers as $col => $field) {
                $value = isset($line[$col]) ? trim((string) $line[$col]) : '';
                if ($value !== '') {
                    $empty = false;
                }
                if ($field !== null) {
                    $raw[$field] = $value;
                }
            }
            if (! $empty) {
                $rawRows[] = $raw;
            }
        }

        return $this->stage($request, $service, $rawRows, 'excel');
    }

    public function dryRunGrid(Request $request, BalanceImportService $service): RedirectResponse
    {
        $request->validate([
            'samity_id' => ['required', 'integer', 'exists:samities,id'],
            'transaction_date' => ['required', 'date'],
            'add_members' => ['required', 'boolean'],
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*' => ['array'],
        ]);
        $rawRows = collect($request->input('rows', []))
            ->map(fn ($r) => collect((array) $r)->map(fn ($v) => is_scalar($v) ? trim((string) $v) : '')->all())
            ->filter(fn ($r) => collect($r)->filter(fn ($v) => $v !== '')->isNotEmpty())
            ->values()->all();

        return $this->stage($request, $service, $rawRows, 'grid');
    }

    public function confirm(Request $request, BalanceImportBatch $batch, AuditService $audit): RedirectResponse
    {
        abort_unless($batch->status === 'Preview', 422);
        $batch->update(['status' => 'Queued']);
        ImportBalancesJob::dispatch($batch->id);
        $audit->log($request->user()->id, 'balance_import.confirmed', 'balance_import_batches', $batch->id, null, ['total_rows' => $batch->total_rows]);

        return redirect()->route('settings.balance-migrations.index')->with('success', __('import_queued'));
    }

    public function errorReport(BalanceImportBatch $batch, XlsxService $xlsx): BinaryFileResponse
    {
        $failures = $batch->failures ?? [];
        abort_if($failures === [], 404);
        $rows = [[__('row'), __('member_no'), __('member_name'), __('reason')]];
        foreach ($failures as $failure) {
            $rows[] = [$failure['row'] ?? '', $failure['member_id'] ?? '', $failure['name'] ?? '', $failure['reason'] ?? ''];
        }
        $path = tempnam(sys_get_temp_dir(), 'balance-import-errors-').'.xlsx';
        $xlsx->write($path, $rows);

        return response()->download($path, 'balance-import-errors-'.$batch->id.'.xlsx')->deleteFileAfterSend();
    }

    private function stage(Request $request, BalanceImportService $service, array $rawRows, string $source): RedirectResponse
    {
        $samity = Samity::withoutGlobalScopes()->findOrFail($request->integer('samity_id'));
        $addMembers = $request->boolean('add_members');
        $rows = [];
        foreach (array_values($rawRows) as $i => $raw) {
            $result = $service->validateRow($raw, $samity, $addMembers);
            $rows[] = ['no' => $i + 1, 'raw' => $raw, 'errors' => $result['errors']];
        }
        $batch = BalanceImportBatch::create([
            'created_by' => $request->user()->id,
            'samity_id' => $samity->id,
            'branch_id' => $samity->branch_id,
            'transaction_date' => $request->date('transaction_date'),
            'add_members' => $addMembers,
            'source' => $source,
            'status' => 'Preview',
            'total_rows' => count($rows),
            'rows' => $rows,
        ]);

        return redirect()->route('settings.balance-migrations.show', $batch);
    }

    /** Map spreadsheet columns to import fields via normalized headers. */
    private function headerMap(array $headerRow): array
    {
        $synonyms = [
            'memberid' => 'member_id', 'memberno' => 'member_id', 'id' => 'member_id',
            'membername' => 'member_name', 'name' => 'member_name',
            'loanproduct' => 'loan_product', 'product' => 'loan_product', 'productcode' => 'loan_product',
            'openingdate' => 'opening_date', 'opendate' => 'opening_date',
            'noofinst' => 'no_of_inst', 'noofinstallments' => 'no_of_inst', 'installments' => 'no_of_inst',
            'instfreq' => 'inst_freq', 'frequency' => 'inst_freq',
            'instamount' => 'inst_amount', 'installmentamount' => 'inst_amount',
            'instfromdate' => 'inst_from_date', 'installmentfromdate' => 'inst_from_date', 'firstinstallmentdate' => 'inst_from_date',
            'loanamount' => 'loan_amount',
            'profitrate' => 'profit_rate', 'rate' => 'profit_rate',
            'totalloan' => 'total_loan', 'total' => 'total_loan',
            'totalreceive' => 'total_receive', 'totalreceived' => 'total_receive', 'received' => 'total_receive',
            'generalsavings' => 'general_savings', 'savings' => 'general_savings',
            'compulsorysavings' => 'compulsory_savings', 'compulsory' => 'compulsory_savings',
        ];
        $map = [];
        foreach ($headerRow as $col => $header) {
            $key = preg_replace('/[^a-z0-9]/', '', strtolower((string) $header));
            $map[$col] = $synonyms[$key] ?? null;
        }

        return $map;
    }
}
