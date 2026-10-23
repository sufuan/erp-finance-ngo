<?php

namespace App\Services\Excel;

use App\Models\User;
use App\Services\XlsxService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Excel import/export engine (owner spec, 2026-10-22): ONE reusable core
 * behind every Excel import in the app. A dataset (ImportDataset) only
 * declares its columns, its row rules and how a valid row is saved; the
 * engine generates the template + export from those same definitions (so
 * template, parser and validator can never drift apart), parses uploads,
 * and runs the preview → re-validate → confirm cycle:
 *
 *   preview   upload → parse → validate → per-cell errors as JSON
 *   validate  edited rows → full re-validation (DB rules included)
 *   confirm   re-validates server-side; zero errors → ALL rows written
 *             in ONE transaction (all-or-nothing); any error → nothing
 *             written and the error map comes back (HTTP 422 upstream).
 */
class ExcelImportEngine
{
    public function __construct(private XlsxService $xlsx) {}

    /** Column definitions as the frontend grid consumes them. */
    public function uiColumns(ImportDataset $dataset): array
    {
        return array_map(fn (array $col) => [
            'key' => $col['key'],
            'label' => __($col['label']),
            'required' => (bool) ($col['required'] ?? false),
            'input' => $col['input'] ?? 'text',
            'options' => $col['options'] ?? [],
        ], $dataset->columns());
    }

    /** Template sheets: [data sheet: headers + one example row, Instructions]. */
    public function templateSheets(ImportDataset $dataset): array
    {
        $headers = ['SL'];
        $example = ['1'];
        foreach ($dataset->columns() as $col) {
            $headers[] = $col['header'];
            $example[] = (string) ($col['example'] ?? '');
        }

        $instructions = [[__('excel_column'), __('excel_required'), __('excel_example'), __('excel_notes')]];
        foreach ($dataset->columns() as $col) {
            $instructions[] = [
                $col['header'],
                ($col['required'] ?? false) ? __('yes') : __('no'),
                (string) ($col['example'] ?? ''),
                isset($col['hint']) ? __($col['hint']) : '',
            ];
        }
        $instructions[] = [];
        $instructions[] = [__('excel_template_instructions')];

        return [$dataset->sheetName() => [$headers, $example], 'Instructions' => $instructions];
    }

    /** Export sheets: [data sheet: headers + one row per current record]. */
    public function exportSheets(ImportDataset $dataset, User $actor): array
    {
        $headers = ['SL'];
        foreach ($dataset->columns() as $col) {
            $headers[] = $col['header'];
        }
        $rows = [$headers];
        foreach ($dataset->exportRows($actor) as $i => $cells) {
            $line = [(string) ($i + 1)];
            foreach ($dataset->columns() as $col) {
                $line[] = (string) ($cells[$col['key']] ?? '');
            }
            $rows[] = $line;
        }

        return [$dataset->sheetName() => $rows];
    }

    /**
     * Parse an uploaded spreadsheet into cell maps. Columns are matched
     * by normalized header text (the fixed header or any declared alias);
     * unmapped columns (SL, extras) are ignored; fully-empty rows drop.
     *
     * @return array{rows: array<int, array<string, string>>, matched: bool}
     */
    public function parseUpload(UploadedFile $file, ImportDataset $dataset): array
    {
        $grid = $this->xlsx->read($file->getRealPath());
        $headerRow = array_shift($grid) ?? [];
        $map = $this->headerMap($dataset, $headerRow);
        if (! collect($map)->contains(fn ($key) => $key !== null)) {
            return ['rows' => [], 'matched' => false];
        }

        $rows = [];
        foreach ($grid as $line) {
            $cells = [];
            $empty = true;
            foreach ($dataset->columns() as $col) {
                $cells[$col['key']] = '';
            }
            foreach ($map as $colIndex => $key) {
                if ($key === null) {
                    continue;
                }
                $value = isset($line[$colIndex]) ? trim((string) $line[$colIndex]) : '';
                if ($value !== '') {
                    $empty = false;
                }
                $cells[$key] = $value;
            }
            if (! $empty) {
                $rows[] = $cells;
            }
        }

        return ['rows' => $rows, 'matched' => true];
    }

    /**
     * Normalize raw JSON rows (from the editable grid) to cell maps:
     * known column keys only, scalar values trimmed to strings. Rows are
     * KEPT even when fully blank — a blank grid row must come back with
     * its required-cell errors, not silently vanish (and at confirm time
     * those errors block the import until the row is filled or removed).
     *
     * @return array<int, array<string, string>>
     */
    public function normalizeRows(ImportDataset $dataset, array $rawRows): array
    {
        $keys = array_column($dataset->columns(), 'key');
        $rows = [];
        foreach ($rawRows as $raw) {
            $cells = [];
            foreach ($keys as $key) {
                $cells[$key] = isset($raw[$key]) && is_scalar($raw[$key]) ? trim((string) $raw[$key]) : '';
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * Full validation of cell maps — the shape every JSON endpoint
     * returns: rows with 1-based numbers, their cells, and per-cell
     * errors. `data` stays server-side (confirm re-derives it anyway).
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array{rows: array<int, array{row: int, cells: array<string, string>, errors: array<string, string>}>, error_count: int}
     */
    public function validate(ImportDataset $dataset, array $rows, array $context, User $actor): array
    {
        $validated = $dataset->validateRows($rows, $context, $actor);
        $out = [];
        $errorCount = 0;
        foreach ($validated as $i => $result) {
            $errorCount += count($result['errors']);
            $out[] = ['row' => $i + 1, 'cells' => $result['cells'], 'errors' => $result['errors']];
        }

        return ['rows' => $out, 'error_count' => $errorCount];
    }

    /**
     * Confirm an import: re-validate server-side (the client is never
     * trusted); zero errors → every row saved inside ONE transaction,
     * then the dataset's batch record — all-or-nothing.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array{ok: bool, imported: int, batch_id: ?int, validation: array}
     */
    public function import(ImportDataset $dataset, array $rows, array $context, string $source, User $actor): array
    {
        $validated = $dataset->validateRows($rows, $context, $actor);
        $errorCount = 0;
        $public = [];
        foreach ($validated as $i => $result) {
            $errorCount += count($result['errors']);
            $public[] = ['row' => $i + 1, 'cells' => $result['cells'], 'errors' => $result['errors']];
        }
        if ($errorCount > 0) {
            return ['ok' => false, 'imported' => 0, 'batch_id' => null,
                'validation' => ['rows' => $public, 'error_count' => $errorCount]];
        }

        $batchId = DB::transaction(function () use ($dataset, $validated, $context, $source, $actor) {
            foreach ($validated as $result) {
                $dataset->saveRow($result['data'], $actor);
            }

            return $dataset->recordBatch($actor, $source, $context, $validated, count($validated));
        });

        return ['ok' => true, 'imported' => count($validated), 'batch_id' => $batchId,
            'validation' => ['rows' => $public, 'error_count' => 0]];
    }

    /** Map spreadsheet column indexes to column keys via normalized headers. */
    private function headerMap(ImportDataset $dataset, array $headerRow): array
    {
        $synonyms = [];
        foreach ($dataset->columns() as $col) {
            $synonyms[$this->normalizeHeader($col['header'])] = $col['key'];
            foreach ($col['aliases'] ?? [] as $alias) {
                $synonyms[$this->normalizeHeader($alias)] = $col['key'];
            }
        }
        $map = [];
        foreach ($headerRow as $colIndex => $header) {
            $map[$colIndex] = $synonyms[$this->normalizeHeader((string) $header)] ?? null;
        }

        return $map;
    }

    private function normalizeHeader(string $header): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($header));
    }
}
