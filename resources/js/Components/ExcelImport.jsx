import { useEffect, useRef, useState } from 'react';
import { CheckCircle2, FileSpreadsheet, Plus, Trash2, UploadCloud, XCircle } from 'lucide-react';
import { ButtonSpinner, PrimaryButton } from './ui';
import { t } from '../lib/i18n';

function xsrf() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function postJson(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json' },
        body: JSON.stringify(body),
    });
    let json = null;
    try { json = await response.json(); } catch { /* non-JSON error page */ }
    return { status: response.status, json };
}

const blankCells = (columns) => Object.fromEntries(columns.map((c) => [c.key, '']));

/**
 * Excel import engine — shared editable preview (owner spec 2026-10-22).
 *
 * Driven entirely by a dataset config: `columns` (from the server, so the
 * grid can never drift from the template/validator) and `endpoints`
 * ({preview, validate, confirm}). `context` is merged into every request
 * (e.g. a default samity chosen on the page). Flow: upload → server
 * preview with per-cell errors → edit cells in place → debounced server
 * re-validation → Confirm stays DISABLED until a validation round returns
 * zero errors → all-or-nothing import.
 */
export default function ExcelImport({ columns, endpoints, context = {}, onImported }) {
    const [rows, setRows] = useState([]);
    const [source, setSource] = useState('excel');
    const [fileName, setFileName] = useState('');
    const [busy, setBusy] = useState(''); // '' | 'parsing' | 'validating' | 'importing'
    const [stale, setStale] = useState(false); // edited since last server validation
    const [message, setMessage] = useState('');
    const [doneCount, setDoneCount] = useState(null);
    const [dragOver, setDragOver] = useState(false);
    const fileRef = useRef(null);
    const validateSeq = useRef(0);
    const debounceRef = useRef(null);

    const errorCount = rows.reduce((sum, r) => sum + Object.keys(r.errors ?? {}).length, 0);
    const allValid = rows.length > 0 && errorCount === 0 && !stale && busy === '';

    useEffect(() => () => clearTimeout(debounceRef.current), []);

    // Context (e.g. the page's default samity) feeds validation — when it
    // changes mid-edit, the current rows must be re-checked against it.
    const contextJson = JSON.stringify(context);
    const prevContextJson = useRef(contextJson);
    useEffect(() => {
        if (prevContextJson.current !== contextJson) {
            prevContextJson.current = contextJson;
            if (rows.length > 0) scheduleValidation(rows);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [contextJson]);

    const applyResult = (json) => {
        setRows((json?.rows ?? []).map((r) => ({ cells: r.cells ?? {}, errors: r.errors ?? {} })));
        setStale(false);
    };

    const runValidation = async (nextRows) => {
        const seq = ++validateSeq.current;
        setBusy('validating');
        let outcome;
        try {
            outcome = await postJson(endpoints.validate, {
                ...context,
                rows: nextRows.map((r) => r.cells),
            });
        } catch {
            outcome = null; // network failure — handled like a server error below
        }
        if (seq !== validateSeq.current) return; // a newer edit superseded this round
        setBusy('');
        if (outcome && outcome.status === 200 && outcome.json) {
            applyResult(outcome.json);
        } else {
            // Validation did NOT run: keep the rows marked stale so Confirm
            // stays blocked instead of trusting the previous round's errors.
            setMessage(outcome?.json?.message ?? t('import_failed'));
            setStale(true);
        }
    };

    const scheduleValidation = (nextRows) => {
        setStale(true);
        clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => runValidation(nextRows), 600);
    };

    const uploadFile = async (file) => {
        if (!file || busy === 'parsing') return;
        setMessage('');
        setDoneCount(null);
        setBusy('parsing');
        const form = new FormData();
        form.append('file', file);
        Object.entries(context).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') form.append(k, v); });
        let response = null;
        let json = null;
        try {
            response = await fetch(endpoints.preview, {
                method: 'POST',
                headers: { 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json' },
                body: form,
            });
            try { json = await response.json(); } catch { /* ignore */ }
        } catch {
            response = null; // network failure — falls into the error message below
        }
        setBusy('');
        if (response && response.status === 200 && json) {
            setSource('excel');
            setFileName(file.name);
            applyResult(json);
            if ((json.rows ?? []).length === 0) setMessage(t('no_data_found'));
        } else {
            setMessage(json?.message ?? Object.values(json?.errors ?? {})?.flat()?.[0] ?? t('import_failed'));
        }
    };

    const setCell = (index, key, value) => {
        setRows((current) => {
            const next = current.map((r, i) => (i === index ? { ...r, cells: { ...r.cells, [key]: value } } : r));
            scheduleValidation(next);
            return next;
        });
    };

    const addRow = () => {
        setDoneCount(null);
        if (rows.length === 0) setSource('grid');
        setRows((current) => {
            const next = [...current, { cells: blankCells(columns), errors: {} }];
            scheduleValidation(next);
            return next;
        });
    };

    const removeRow = (index) => {
        setRows((current) => {
            const next = current.filter((_, i) => i !== index);
            if (next.length > 0) scheduleValidation(next);
            else { setStale(false); clearTimeout(debounceRef.current); }
            return next;
        });
    };

    const confirmImport = async () => {
        setMessage('');
        setBusy('importing');
        let outcome;
        try {
            outcome = await postJson(endpoints.confirm, {
                ...context,
                source,
                rows: rows.map((r) => r.cells),
            });
        } catch {
            outcome = null; // network failure — busy must still reset below
        }
        setBusy('');
        const { status, json } = outcome ?? {};
        if (status === 200 && json?.ok) {
            setDoneCount(json.imported);
            setRows([]);
            setFileName('');
            setStale(false);
            onImported?.(json);
        } else if (status === 422 && json?.rows) {
            applyResult(json); // server found errors the client missed — show them
            setMessage(t('excel_fix_errors'));
        } else {
            setMessage(json?.message ?? t('import_failed'));
        }
    };

    const reset = () => {
        setRows([]);
        setFileName('');
        setMessage('');
        setDoneCount(null);
        setStale(false);
    };

    const cellCls = (hasError) => `w-full min-w-[110px] rounded-md border px-2 py-1.5 text-sm outline-none transition ${hasError
        ? 'border-red-400 bg-red-50 text-red-900 focus:border-red-500 dark:border-red-500/60 dark:bg-red-900/20 dark:text-red-100'
        : 'border-zinc-300 bg-white focus:border-emerald-500 dark:border-zinc-700 dark:bg-zinc-900'}`;

    return (
        <div>
            {/* Upload zone */}
            <div
                role="button"
                tabIndex={0}
                aria-label={t('excel_upload_drop')}
                onClick={() => fileRef.current?.click()}
                onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileRef.current?.click(); } }}
                onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
                onDragLeave={() => setDragOver(false)}
                onDrop={(e) => { e.preventDefault(); setDragOver(false); uploadFile(e.dataTransfer.files?.[0]); }}
                className={`cursor-pointer rounded-xl border-2 border-dashed px-4 py-8 text-center transition ${dragOver
                    ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20'
                    : 'border-zinc-300 hover:border-emerald-400 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/50'}`}
            >
                <input ref={fileRef} type="file" accept=".xlsx,.csv" className="hidden"
                    onChange={(e) => { uploadFile(e.target.files?.[0]); e.target.value = ''; }} />
                {busy === 'parsing'
                    ? <div className="flex items-center justify-center gap-2 text-sm text-zinc-500"><ButtonSpinner /> {t('excel_validating')}</div>
                    : (
                        <>
                            <UploadCloud className="mx-auto mb-2 h-9 w-9 text-emerald-600 dark:text-emerald-400" />
                            <p className="text-sm font-medium">{t('excel_upload_drop')}</p>
                            <p className="mt-1 text-xs text-zinc-500">{t('excel_or_browse')} (.xlsx / .csv)</p>
                            {fileName && <p className="mt-2 inline-flex items-center gap-1 text-xs font-medium text-emerald-700 dark:text-emerald-400"><FileSpreadsheet className="h-3.5 w-3.5" /> {fileName}</p>}
                        </>
                    )}
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-2">
                <button type="button" onClick={addRow} disabled={busy === 'importing'}
                    className="inline-flex min-h-[40px] items-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-1.5 text-sm hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:hover:bg-zinc-800">
                    <Plus className="h-4 w-4" /> {t('excel_add_row')}
                </button>
                {message && <p className="text-sm text-red-600 dark:text-red-400">{message}</p>}
                {doneCount !== null && (
                    <p className="inline-flex items-center gap-1.5 text-sm font-medium text-emerald-700 dark:text-emerald-400">
                        <CheckCircle2 className="h-4 w-4" /> {t('excel_imported_success')} — {doneCount}
                    </p>
                )}
            </div>

            {/* Editable preview grid */}
            {rows.length > 0 && (
                <div className="mt-4">
                    <h3 className="mb-2 font-medium">{t('excel_preview_title')}</h3>
                    <div className="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-800">
                        <table className="w-full min-w-[1150px] border-collapse text-left text-sm">
                            <thead>
                                <tr className="bg-zinc-50 dark:bg-zinc-800/60">
                                    <th className="px-2 py-2 font-medium">#</th>
                                    {columns.map((col) => (
                                        <th key={col.key} className="px-2 py-2 font-medium">
                                            {col.label}{col.required && <span className="text-red-500"> *</span>}
                                        </th>
                                    ))}
                                    <th className="px-2 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row, i) => (
                                    <tr key={i} className="border-t border-zinc-100 align-top dark:border-zinc-800">
                                        <td className="px-2 py-1.5 text-zinc-500">{i + 1}</td>
                                        {columns.map((col) => {
                                            const error = row.errors?.[col.key];
                                            return (
                                                <td key={col.key} className="px-2 py-1.5">
                                                    {col.input === 'select' ? (
                                                        <select className={cellCls(!!error)} value={row.cells[col.key] ?? ''}
                                                            onChange={(e) => setCell(i, col.key, e.target.value)}>
                                                            <option value="">—</option>
                                                            {(col.options ?? []).map((o) => <option key={o} value={o}>{o}</option>)}
                                                        </select>
                                                    ) : (
                                                        <input type="text" className={cellCls(!!error)} value={row.cells[col.key] ?? ''}
                                                            placeholder={col.input === 'date' ? 'YYYY-MM-DD' : ''}
                                                            onChange={(e) => setCell(i, col.key, e.target.value)} />
                                                    )}
                                                    {error && <p className="mt-1 text-xs leading-snug text-red-600 dark:text-red-400">{error}</p>}
                                                </td>
                                            );
                                        })}
                                        <td className="px-2 py-1.5">
                                            <button type="button" title={t('excel_remove_row')} aria-label={t('excel_remove_row')}
                                                onClick={() => removeRow(i)}
                                                className="rounded-lg p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">
                                                <Trash2 className="h-4 w-4" />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Status bar: red while errors/stale, green at zero errors */}
                    <div className={`mt-3 flex flex-wrap items-center gap-3 rounded-xl border px-4 py-3 ${allValid
                        ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-900/20'
                        : 'border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-900/20'}`}>
                        <span className="inline-flex items-center gap-2 text-sm font-medium">
                            {allValid
                                ? <><CheckCircle2 className="h-5 w-5 text-emerald-600 dark:text-emerald-400" /> {t('excel_all_valid')}</>
                                : <><XCircle className="h-5 w-5 text-red-500" /> {t('total_rows')}: {rows.length} · {t('errors')}: {errorCount} — {t('excel_fix_errors')}</>}
                        </span>
                        {busy === 'validating' && <span className="inline-flex items-center gap-1.5 text-xs text-zinc-500"><ButtonSpinner className="h-3.5 w-3.5" /> {t('excel_validating')}</span>}
                        <span className="ml-auto flex gap-2">
                            <button type="button" onClick={reset} disabled={busy === 'importing'}
                                className="min-h-[40px] rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900">
                                {t('excel_start_again')}
                            </button>
                            <PrimaryButton type="button" onClick={confirmImport} loading={busy === 'importing'} disabled={!allValid}>
                                {t('confirm_import')}
                            </PrimaryButton>
                        </span>
                    </div>
                </div>
            )}
        </div>
    );
}
