<?php

namespace App\Services\Excel;

use App\Models\User;

/**
 * Excel import/export engine — dataset contract.
 *
 * One dataset = one importable/exportable table (members today; balance
 * migrations and employees later plug in by implementing this interface —
 * no engine changes). A column definition is an array:
 *
 *   key      string  Machine key used in cell maps and error maps.
 *   header   string  FIXED Excel header text (template + parse target).
 *                    Never translated, so a saved template always parses.
 *   label    string  i18n key for the UI grid header.
 *   required bool    Shown with a red asterisk; enforced by validateRows.
 *   example  string  Example value placed in the template's example row.
 *   hint     ?string i18n key explaining the column on the Instructions
 *                    sheet (null = no note).
 *   aliases  string[] Extra normalized header synonyms accepted on parse
 *                    (the header itself, normalized, is always accepted).
 *   input    string  UI cell editor: 'text' | 'date' | 'select'.
 *   options  string[] Choices when input = 'select'.
 *
 * Rows everywhere are "cell maps": array<string, string> keyed by column
 * key. Validation returns, per row: the cells, a per-cell error map
 * (column key => message; empty = valid) and the normalized `data` the
 * saver consumes (null while the row has errors).
 */
interface ImportDataset
{
    /** Machine key, e.g. 'members'. */
    public function key(): string;

    /** Excel sheet name for template/export (first sheet). */
    public function sheetName(): string;

    /** @return array<int, array<string, mixed>> Column definitions. */
    public function columns(): array;

    /**
     * Validate + normalize every row (read-only; saves nothing).
     * $context carries page-level defaults (members: ['samity_id' => ?int]).
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array{cells: array<string, string>, errors: array<string, string>, data: ?array}>
     */
    public function validateRows(array $rows, array $context, User $actor): array;

    /** Persist ONE normalized row (the `data` from validateRows). */
    public function saveRow(array $data, User $actor): void;

    /**
     * Current rows for export, as cell maps (column key => display value).
     *
     * @return array<int, array<string, string>>
     */
    public function exportRows(User $actor): array;

    /**
     * Record a completed import in the dataset's own history/batch table
     * (members: member_import_batches). Runs inside the import
     * transaction, after all rows saved. Returns the record id if any.
     *
     * @param  array<int, array{cells: array<string, string>}>  $rows
     */
    public function recordBatch(User $actor, string $source, array $context, array $rows, int $imported): ?int;
}
