<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\ImportMembersJob;
use App\Models\MemberImportBatch;
use App\Models\Samity;
use App\Services\AuditService;
use App\Services\Excel\Datasets\MemberDataset;
use App\Services\Excel\ExcelImportEngine;
use App\Services\MemberImportRowValidator;
use App\Services\XlsxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Step 9A — Settings → Member Migrations. Dry-run preview first (nothing
 * saved), then a queued chunked import with per-row transactions, then a
 * downloadable error report for the rows that failed.
 *
 * Excel engine (2026-10-22) — the page's primary flow now runs on the
 * reusable ExcelImportEngine + MemberDataset: template download, export,
 * and JSON preview → in-place edit → re-validate → all-or-nothing
 * confirm. The Step 9A staged-batch endpoints above stay for history
 * batches created by the old flow.
 */
class MemberMigrationController extends Controller
{
    public function index(?MemberImportBatch $batch = null): Response
    {
        $batches = MemberImportBatch::with('creator:id,username')
            ->orderByDesc('id')->limit(20)->get()
            ->map(fn (MemberImportBatch $b) => [
                'id' => $b->id,
                'created_at' => $b->created_at?->toDateTimeString(),
                'created_by' => $b->creator?->username,
                'source' => $b->source,
                'status' => $b->status,
                'total_rows' => $b->total_rows,
                'imported_count' => $b->imported_count,
                'failed_count' => $b->failed_count,
            ])->all();

        $preview = null;
        if ($batch && $batch->exists) {
            $rows = collect($batch->rows ?? []);
            $preview = [
                'id' => $batch->id,
                'status' => $batch->status,
                'source' => $batch->source,
                'total' => $batch->total_rows,
                'ok_count' => $rows->where('errors', [])->count(),
                'error_count' => $rows->filter(fn ($r) => ($r['errors'] ?? []) !== [])->count(),
                'rows' => $rows->map(fn ($r) => [
                    'no' => $r['no'],
                    'raw' => $r['raw'],
                    'errors' => $r['errors'] ?? [],
                ])->all(),
            ];
        }

        return Inertia::render('Settings/MemberMigrations', [
            'samities' => Samity::orderBy('samity_code')->get(['id', 'samity_code', 'name'])
                ->map(fn (Samity $s) => ['id' => $s->id, 'label' => $s->samity_code.' — '.$s->name])->all(),
            'batches' => $batches,
            'preview' => $preview,
            'excelColumns' => app(ExcelImportEngine::class)->uiColumns(app(MemberDataset::class)),
        ]);
    }

    /** Excel engine: download the import template (headers + example + instructions). */
    public function template(ExcelImportEngine $engine, MemberDataset $dataset, XlsxService $xlsx): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'member-import-template-').'.xlsx';
        $xlsx->writeSheets($path, $engine->templateSheets($dataset));

        return response()->download($path, 'member-import-template.xlsx')->deleteFileAfterSend();
    }

    /** Excel engine: export the branch-visible members as .xlsx. */
    public function export(Request $request, ExcelImportEngine $engine, MemberDataset $dataset, XlsxService $xlsx): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'members-export-').'.xlsx';
        $xlsx->writeSheets($path, $engine->exportSheets($dataset, $request->user()));

        return response()->download($path, 'members-export.xlsx')->deleteFileAfterSend();
    }

    /** Excel engine: upload → parse → validate → per-cell errors as JSON. Nothing saved. */
    public function previewJson(Request $request, ExcelImportEngine $engine, MemberDataset $dataset): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv', 'max:10240'],
            'samity_id' => ['nullable', 'integer', 'exists:samities,id'],
        ]);

        $parsed = $engine->parseUpload($request->file('file'), $dataset);
        if (! $parsed['matched']) {
            return response()->json(['message' => __('excel_headers_not_recognized')], 422);
        }

        $result = $engine->validate($dataset, $parsed['rows'], $this->excelContext($request), $request->user());

        return response()->json(['total' => count($result['rows'])] + $result);
    }

    /** Excel engine: re-validate (possibly edited) preview rows as JSON. Nothing saved. */
    public function validateRowsJson(Request $request, ExcelImportEngine $engine, MemberDataset $dataset): JsonResponse
    {
        $rows = $this->validatedRowPayload($request, $engine, $dataset);
        $result = $engine->validate($dataset, $rows, $this->excelContext($request), $request->user());

        return response()->json(['total' => count($result['rows'])] + $result);
    }

    /**
     * Excel engine: confirm an import. The server re-validates the final
     * rows; any error → 422 with the per-cell map and NOTHING written;
     * zero errors → all rows admitted in one transaction + batch record.
     */
    public function confirmImportJson(Request $request, ExcelImportEngine $engine, MemberDataset $dataset, AuditService $audit): JsonResponse
    {
        $request->validate(['source' => ['nullable', 'in:excel,grid']]);
        $rows = $this->validatedRowPayload($request, $engine, $dataset);

        try {
            $result = $engine->import($dataset, $rows, $this->excelContext($request), $request->input('source', 'excel'), $request->user());
        } catch (\Throwable $e) {
            return response()->json(['message' => __('import_failed').': '.$e->getMessage()], 422);
        }

        if (! $result['ok']) {
            return response()->json(['ok' => false] + $result['validation'], 422);
        }

        $audit->log($request->user()->id, 'member_import.confirmed', 'member_import_batches', $result['batch_id'], null,
            ['total_rows' => $result['imported'], 'via' => 'excel_engine']);

        return response()->json(['ok' => true, 'imported' => $result['imported'], 'batch_id' => $result['batch_id']]);
    }

    /** Shared rows-payload validation + normalization for validate/confirm. */
    private function validatedRowPayload(Request $request, ExcelImportEngine $engine, MemberDataset $dataset): array
    {
        $request->validate([
            'samity_id' => ['nullable', 'integer', 'exists:samities,id'],
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*' => ['array'],
        ]);

        return $engine->normalizeRows($dataset, $request->input('rows', []));
    }

    private function excelContext(Request $request): array
    {
        return ['samity_id' => $request->filled('samity_id') ? $request->integer('samity_id') : null];
    }

    public function dryRunExcel(Request $request, MemberImportRowValidator $validator, XlsxService $xlsx): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv', 'max:10240'],
            'samity_id' => ['nullable', 'integer', 'exists:samities,id'],
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

        return $this->stage($request, $validator, $rawRows, 'excel');
    }

    public function dryRunGrid(Request $request, MemberImportRowValidator $validator): RedirectResponse
    {
        $request->validate([
            'samity_id' => ['nullable', 'integer', 'exists:samities,id'],
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*' => ['array'],
        ]);
        $rawRows = collect($request->input('rows', []))
            ->map(fn ($r) => collect((array) $r)->map(fn ($v) => is_scalar($v) ? trim((string) $v) : '')->all())
            ->filter(fn ($r) => collect($r)->filter(fn ($v) => $v !== '')->isNotEmpty())
            ->values()->all();

        return $this->stage($request, $validator, $rawRows, 'grid');
    }

    public function confirm(Request $request, MemberImportBatch $batch, AuditService $audit): RedirectResponse
    {
        abort_unless($batch->status === 'Preview', 422);
        $batch->update(['status' => 'Queued']);
        ImportMembersJob::dispatch($batch->id);
        $audit->log($request->user()->id, 'member_import.confirmed', 'member_import_batches', $batch->id, null, ['total_rows' => $batch->total_rows]);

        return redirect()->route('settings.member-migrations.index')->with('success', __('import_queued'));
    }

    public function errorReport(MemberImportBatch $batch, XlsxService $xlsx): BinaryFileResponse
    {
        $failures = $batch->failures ?? [];
        abort_if($failures === [], 404);

        $rows = [[__('row'), __('member_no'), __('full_name'), __('mobile'), __('reason')]];
        foreach ($failures as $failure) {
            $rows[] = [
                $failure['row'] ?? '', $failure['member_id'] ?? '', $failure['name'] ?? '',
                $failure['mobile'] ?? '', $failure['reason'] ?? '',
            ];
        }
        $path = tempnam(sys_get_temp_dir(), 'member-import-errors-').'.xlsx';
        $xlsx->write($path, $rows);

        return response()->download($path, 'member-import-errors-'.$batch->id.'.xlsx')->deleteFileAfterSend();
    }

    /** Validate every row, persist the staged batch, go to the preview. */
    private function stage(Request $request, MemberImportRowValidator $validator, array $rawRows, string $source): RedirectResponse
    {
        $defaultSamity = $request->filled('samity_id')
            ? Samity::withoutGlobalScopes()->find($request->integer('samity_id'))
            : null;

        $seenMobiles = [];
        $seenNids = [];
        $rows = [];
        foreach (array_values($rawRows) as $i => $raw) {
            $result = $validator->validate($raw, $defaultSamity, $seenMobiles, $seenNids);
            $rows[] = ['no' => $i + 1, 'raw' => $raw, 'errors' => $result['errors']];
        }

        $batch = MemberImportBatch::create([
            'created_by' => $request->user()->id,
            'samity_id' => $defaultSamity?->id,
            'source' => $source,
            'status' => 'Preview',
            'total_rows' => count($rows),
            'rows' => $rows,
        ]);

        return redirect()->route('settings.member-migrations.show', $batch);
    }

    /** Map spreadsheet columns to import fields via normalized headers. */
    private function headerMap(array $headerRow): array
    {
        $synonyms = [
            'memberid' => 'member_id', 'memberno' => 'member_id', 'id' => 'member_id',
            'membername' => 'name', 'name' => 'name', 'fullname' => 'name',
            'samity' => 'samity', 'samityname' => 'samity', 'samitycode' => 'samity',
            'gender' => 'gender', 'sex' => 'gender',
            'fathershusbandsname' => 'father_name', 'fathersname' => 'father_name',
            'fatherhusbandname' => 'father_name', 'fathername' => 'father_name', 'father' => 'father_name',
            'mobile' => 'mobile', 'mobileno' => 'mobile', 'mobilenumber' => 'mobile', 'phone' => 'mobile',
            'presentaddress' => 'present_address', 'address' => 'present_address',
            'admissiondate' => 'admission_date', 'opendate' => 'admission_date', 'joiningdate' => 'admission_date', 'date' => 'admission_date',
            'nid' => 'national_id', 'nationalid' => 'national_id', 'nationalidno' => 'national_id', 'nidno' => 'national_id',
            'primaryproduct' => 'primary_product', 'product' => 'primary_product',
        ];
        $map = [];
        foreach ($headerRow as $col => $header) {
            $key = preg_replace('/[^a-z0-9]/', '', strtolower((string) $header));
            $map[$col] = $synonyms[$key] ?? null;
        }

        return $map;
    }
}
