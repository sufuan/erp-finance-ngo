import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

const blankRow = () => ({
    member_id: '', name: '', samity: '', gender: 'Female', father_name: '', mobile: '',
    present_address: '', admission_date: '', national_id: '', primary_product: '',
});
const GRID_COLS = [
    ['member_id', 'member_no'], ['name', 'full_name'], ['samity', 'samity'], ['gender', 'gender'],
    ['father_name', 'fathers_husbands_name'], ['mobile', 'mobile'], ['present_address', 'present_address'],
    ['admission_date', 'open_date'], ['national_id', 'national_id'], ['primary_product', 'primary_product'],
];

/** Member migration (Step 9A): Excel or blank-grid dry run → preview → queued import. */
export default function MemberMigrations({ samities, batches, preview }) {
    const excelForm = useForm({ file: null, samity_id: '' });
    const [rowCount, setRowCount] = useState(5);
    const [gridRows, setGridRows] = useState([]);
    const gridForm = useForm({ samity_id: '', rows: [] });

    const submitExcel = (e) => { e.preventDefault(); excelForm.post('/settings/member-migrations/dry-run/excel', { forceFormData: true }); };
    const createBlank = () => setGridRows(Array.from({ length: Math.max(1, Number(rowCount) || 1) }, blankRow));
    const setCell = (i, key, value) => setGridRows((rows) => rows.map((r, idx) => (idx === i ? { ...r, [key]: value } : r)));
    const submitGrid = (e) => {
        e.preventDefault();
        router.post('/settings/member-migrations/dry-run/grid', { samity_id: gridForm.data.samity_id, rows: gridRows });
    };
    const confirmImport = () => router.post(`/settings/member-migrations/${preview.id}/confirm`);

    const rawCell = (raw, key) => (raw?.[key] === undefined || raw?.[key] === '' ? '—' : String(raw[key]));

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('member_migrations')}</h1>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <h2 className="mb-3 font-medium">{t('excel_import')}</h2>
                    <form onSubmit={submitExcel} className="grid gap-4">
                        <Field label={t('samity')} error={excelForm.errors.samity_id}>
                            <select className={inputCls} value={excelForm.data.samity_id} onChange={(e) => excelForm.setData('samity_id', e.target.value)}>
                                <option value="">—</option>
                                {samities.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
                            </select>
                        </Field>
                        <Field label={t('choose_excel_file')} required error={excelForm.errors.file}>
                            <input type="file" accept=".xlsx,.csv" className={inputCls} required
                                onChange={(e) => excelForm.setData('file', e.target.files?.[0] ?? null)} />
                        </Field>
                        <div>
                            <PrimaryButton type="submit" disabled={excelForm.processing}>{t('dry_run')}</PrimaryButton>
                        </div>
                    </form>
                </Card>

                <Card>
                    <h2 className="mb-3 font-medium">{t('blank_rows_grid')}</h2>
                    <div className="flex flex-wrap items-end gap-3">
                        <Field label={t('number_of_members')}>
                            <input type="number" min="1" max="500" className={inputCls} value={rowCount} onChange={(e) => setRowCount(e.target.value)} />
                        </Field>
                        <button type="button" onClick={createBlank} className="min-h-[44px] rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">{t('create_blank_rows')}</button>
                    </div>
                    <Field label={t('samity')}>
                        <select className={inputCls} value={gridForm.data.samity_id} onChange={(e) => gridForm.setData('samity_id', e.target.value)}>
                            <option value="">—</option>
                            {samities.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
                        </select>
                    </Field>
                </Card>
            </div>

            {gridRows.length > 0 && (
                <Card className="mt-4">
                    <form onSubmit={submitGrid}>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[1100px] text-left text-sm">
                                <thead>
                                    <tr className="border-b border-zinc-200 dark:border-zinc-800">
                                        <th className="px-2 py-2">{t('sl')}</th>
                                        {GRID_COLS.map(([key, label]) => <th key={key} className="px-2 py-2">{t(label)}{['name', 'samity', 'gender', 'father_name', 'mobile', 'present_address', 'admission_date'].includes(key) ? ' *' : ''}</th>)}
                                        <th className="px-2 py-2">{t('actions')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {gridRows.map((row, i) => (
                                        <tr key={i} className="border-b border-zinc-100 dark:border-zinc-800">
                                            <td className="px-2 py-1">{i + 1}</td>
                                            {GRID_COLS.map(([key]) => (
                                                <td key={key} className="px-2 py-1">
                                                    {key === 'gender' ? (
                                                        <select className={inputCls} value={row[key]} onChange={(e) => setCell(i, key, e.target.value)}>
                                                            <option value="Female">{t('female')}</option>
                                                            <option value="Male">{t('male')}</option>
                                                            <option value="Other">{t('other')}</option>
                                                        </select>
                                                    ) : (
                                                        <input type={key === 'admission_date' ? 'date' : 'text'} className={inputCls} value={row[key]} onChange={(e) => setCell(i, key, e.target.value)} />
                                                    )}
                                                </td>
                                            ))}
                                            <td className="px-2 py-1">
                                                <button type="button" onClick={() => setGridRows((rows) => rows.filter((_, idx) => idx !== i))} className="min-h-[44px] rounded-lg px-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('delete')}</button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="mt-3 flex gap-2">
                            <button type="button" onClick={() => setGridRows((rows) => [...rows, blankRow()])} className="min-h-[44px] rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">{t('add_new_row')}</button>
                            <PrimaryButton type="submit">{t('dry_run')}</PrimaryButton>
                        </div>
                    </form>
                </Card>
            )}

            {preview && (
                <Card className="mt-4">
                    <div className="mb-3 flex flex-wrap items-center gap-3">
                        <h2 className="font-medium">{t('preview')}</h2>
                        <span className="text-sm text-zinc-500">
                            {t('total_rows')}: {preview.total} · {t('ok')}: {preview.ok_count} · {t('errors')}: {preview.error_count}
                        </span>
                        {preview.status === 'Preview' && (
                            <PrimaryButton onClick={confirmImport} className="ml-auto">{t('confirm_import')}</PrimaryButton>
                        )}
                    </div>
                    <ResponsiveTable head={[t('sl'), ...GRID_COLS.map(([, label]) => t(label)), t('status')]}>
                        {preview.rows.map((r) => (
                            <tr key={r.no} className="border-b border-zinc-100 dark:border-zinc-800">
                                <td className="px-3 py-2">{r.no}</td>
                                {GRID_COLS.map(([key]) => <td key={key} className="px-3 py-2">{rawCell(r.raw, key)}</td>)}
                                <td className="px-3 py-2">
                                    {r.errors.length === 0
                                        ? <span className="text-emerald-600 dark:text-emerald-400">{t('ok')}</span>
                                        : <span className="text-red-600 dark:text-red-400">{r.errors.join('; ')}</span>}
                                </td>
                            </tr>
                        ))}
                        {preview.rows.length === 0 && <tr><td className="px-3 py-3" colSpan={GRID_COLS.length + 2}>{t('no_data_found')}</td></tr>}
                    </ResponsiveTable>
                </Card>
            )}

            <Card className="mt-4">
                <h2 className="mb-3 font-medium">{t('history')}</h2>
                <ResponsiveTable head={[t('date'), t('created_by'), t('source'), t('status'), t('total_rows'), t('imported'), t('failed'), t('actions')]}>
                    {batches.map((b) => (
                        <tr key={b.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{String(b.created_at).slice(0, 10)}</td>
                            <td className="px-3 py-2">{b.created_by ?? '—'}</td>
                            <td className="px-3 py-2">{t(b.source === 'excel' ? 'source_excel' : 'source_grid')}</td>
                            <td className="px-3 py-2">{t(`status_${b.status.toLowerCase()}`)}</td>
                            <td className="px-3 py-2">{b.total_rows}</td>
                            <td className="px-3 py-2">{b.imported_count}</td>
                            <td className="px-3 py-2">{b.failed_count}</td>
                            <td className="px-3 py-2">
                                <span className="flex flex-wrap gap-2">
                                    <Link href={`/settings/member-migrations/${b.id}`} className="text-emerald-700 dark:text-emerald-400">{t('preview')}</Link>
                                    {b.failed_count > 0 && <a href={`/settings/member-migrations/${b.id}/error-report`} className="text-red-600 dark:text-red-400">{t('error_report')}</a>}
                                </span>
                            </td>
                        </tr>
                    ))}
                    {batches.length === 0 && <tr><td className="px-3 py-3" colSpan={8}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
