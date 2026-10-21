import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

const blankRow = () => ({
    member_id: '', member_name: '', loan_product: '', opening_date: '', no_of_inst: '',
    inst_freq: 'M', inst_amount: '', inst_from_date: '', loan_amount: '', profit_rate: '',
    total_loan: '', total_receive: '', general_savings: '', compulsory_savings: '',
});
const GRID_COLS = [
    ['member_id', 'member_no'], ['member_name', 'member_name'], ['loan_product', 'loan_product'],
    ['opening_date', 'opening_date'], ['no_of_inst', 'no_of_installments'], ['inst_freq', 'frequency'],
    ['inst_amount', 'installment_amount'], ['inst_from_date', 'first_installment_date'],
    ['loan_amount', 'loan_amount'], ['profit_rate', 'profit_rate'], ['total_loan', 'total_loan'],
    ['total_receive', 'total_receive'], ['general_savings', 'general_savings'],
    ['compulsory_savings', 'compulsory_savings'],
];

/** Balance migration (Step 20A): Excel/grid dry run → preview → queued import. */
export default function BalanceMigrations({ samities, columns, batches, preview }) {
    const excelForm = useForm({ file: null, samity_id: '', transaction_date: '', add_members: '0' });
    const [gridMeta, setGridMeta] = useState({ samity_id: '', transaction_date: '', add_members: '0' });
    const [rowCount, setRowCount] = useState(3);
    const [gridRows, setGridRows] = useState([]);

    const submitExcel = (e) => { e.preventDefault(); excelForm.post('/settings/balance-migrations/dry-run/excel', { forceFormData: true }); };
    const createBlank = () => setGridRows(Array.from({ length: Math.max(1, Number(rowCount) || 1) }, blankRow));
    const setCell = (i, key, value) => setGridRows((rows) => rows.map((r, idx) => (idx === i ? { ...r, [key]: value } : r)));
    const submitGrid = (e) => {
        e.preventDefault();
        router.post('/settings/balance-migrations/dry-run/grid', { ...gridMeta, rows: gridRows });
    };
    const confirmImport = () => router.post(`/settings/balance-migrations/${preview.id}/confirm`);
    const rawCell = (raw, key) => (raw?.[key] === undefined || raw?.[key] === '' ? '—' : String(raw[key]));
    const num = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);
    const totals = preview ? preview.rows.reduce((acc, r) => ({
        loan: acc.loan + num(r.raw.loan_amount), receive: acc.receive + num(r.raw.total_receive),
        general: acc.general + num(r.raw.general_savings), compulsory: acc.compulsory + num(r.raw.compulsory_savings),
    }), { loan: 0, receive: 0, general: 0, compulsory: 0 }) : null;

    const metaFields = (values, setValues) => (
        <>
            <Field label={`${t('samity')} *`}>
                <select className={inputCls} value={values.samity_id} onChange={(e) => setValues('samity_id', e.target.value)} required>
                    <option value="">—</option>
                    {samities.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
                </select>
            </Field>
            <Field label={`${t('transaction_date')} *`}>
                <input type="date" className={inputCls} value={values.transaction_date} onChange={(e) => setValues('transaction_date', e.target.value)} required />
            </Field>
            <Field label={`${t('add_members')} *`}>
                <select className={inputCls} value={values.add_members} onChange={(e) => setValues('add_members', e.target.value)}>
                    <option value="1">{t('yes')}</option>
                    <option value="0">{t('no')}</option>
                </select>
            </Field>
        </>
    );

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('balance_migrations')}</h1>
            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <h2 className="mb-3 font-medium">{t('excel_import')}</h2>
                    <form onSubmit={submitExcel} className="grid gap-4">
                        {metaFields(excelForm.data, excelForm.setData)}
                        <Field label={`${t('choose_excel_file')} *`} error={excelForm.errors.file}>
                            <input type="file" accept=".xlsx,.csv" className={inputCls} required
                                onChange={(e) => excelForm.setData('file', e.target.files?.[0] ?? null)} />
                        </Field>
                        <div><PrimaryButton type="submit" disabled={excelForm.processing}>{t('dry_run')}</PrimaryButton></div>
                    </form>
                </Card>
                <Card>
                    <h2 className="mb-3 font-medium">{t('blank_rows_grid')}</h2>
                    <div className="grid gap-4">
                        {metaFields(gridMeta, (k, v) => setGridMeta((m) => ({ ...m, [k]: v })))}
                        <div className="flex flex-wrap items-end gap-3">
                            <Field label={t('number_of_members')}>
                                <input type="number" min="1" max="500" className={inputCls} value={rowCount} onChange={(e) => setRowCount(e.target.value)} />
                            </Field>
                            <button type="button" onClick={createBlank} className="min-h-[44px] rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">{t('create_blank_rows')}</button>
                        </div>
                    </div>
                </Card>
            </div>

            {gridRows.length > 0 && (
                <Card className="mt-4">
                    <form onSubmit={submitGrid}>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[1400px] text-left text-sm">
                                <thead>
                                    <tr className="border-b border-zinc-200 dark:border-zinc-800">
                                        <th className="px-2 py-2">{t('sl')}</th>
                                        {GRID_COLS.map(([key, label]) => <th key={key} className="px-2 py-2">{t(label)}</th>)}
                                        <th className="px-2 py-2">{t('actions')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {gridRows.map((row, i) => (
                                        <tr key={i} className="border-b border-zinc-100 dark:border-zinc-800">
                                            <td className="px-2 py-1">{i + 1}</td>
                                            {GRID_COLS.map(([key]) => (
                                                <td key={key} className="px-2 py-1">
                                                    <input type={key.includes('date') ? 'date' : 'text'} className={inputCls} value={row[key]} onChange={(e) => setCell(i, key, e.target.value)} />
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
                        <h2 className="font-medium">{t('dry_run')} — {preview.total} {t('rows')} · {preview.ok_count} OK · {preview.error_count} {t('errors')}</h2>
                        {preview.status === 'Preview' && <PrimaryButton type="button" onClick={confirmImport}>{t('submit_balance_migration')}</PrimaryButton>}
                        {preview.failures?.length > 0 && <a className="text-sm text-emerald-700 dark:text-emerald-400" href={`/settings/balance-migrations/${preview.id}/error-report`}>{t('error_report')}</a>}
                    </div>
                    <ResponsiveTable head={[t('sl'), t('member_no'), t('member_name'), t('loan_product'), t('loan_amount'), t('total_loan'), t('total_receive'), t('general_savings'), t('compulsory_savings'), t('status')]}>
                        {preview.rows.map((r) => (
                            <tr key={r.no} className="border-b border-zinc-100 dark:border-zinc-800">
                                <td className="px-3 py-2">{r.no}</td>
                                <td className="px-3 py-2">{rawCell(r.raw, 'member_id')}</td>
                                <td className="px-3 py-2">{rawCell(r.raw, 'member_name')}</td>
                                <td className="px-3 py-2">{rawCell(r.raw, 'loan_product')}</td>
                                <td className="px-3 py-2">{rawCell(r.raw, 'loan_amount')}</td>
                                <td className="px-3 py-2">{rawCell(r.raw, 'total_loan')}</td>
                                <td className="px-3 py-2">{rawCell(r.raw, 'total_receive')}</td>
                                <td className="px-3 py-2">{rawCell(r.raw, 'general_savings')}</td>
                                <td className="px-3 py-2">{rawCell(r.raw, 'compulsory_savings')}</td>
                                <td className="px-3 py-2">{r.errors.length ? <span className="text-red-600">{r.errors.join('; ')}</span> : 'OK'}</td>
                            </tr>
                        ))}
                        {totals && (
                            <tr className="font-semibold">
                                <td className="px-3 py-2" colSpan={4}>{t('total')}</td>
                                <td className="px-3 py-2">{totals.loan.toFixed(2)}</td>
                                <td className="px-3 py-2"></td>
                                <td className="px-3 py-2">{totals.receive.toFixed(2)}</td>
                                <td className="px-3 py-2">{totals.general.toFixed(2)}</td>
                                <td className="px-3 py-2">{totals.compulsory.toFixed(2)}</td>
                                <td className="px-3 py-2"></td>
                            </tr>
                        )}
                    </ResponsiveTable>
                </Card>
            )}

            <Card className="mt-4">
                <h2 className="mb-3 font-medium">{t('history')}</h2>
                <ResponsiveTable head={[t('date'), t('source'), t('status'), t('rows'), t('imported'), t('failed'), t('actions')]}>
                    {batches.map((b) => (
                        <tr key={b.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{b.created_at}</td>
                            <td className="px-3 py-2">{b.source}</td>
                            <td className="px-3 py-2">{b.status}</td>
                            <td className="px-3 py-2">{b.total_rows}</td>
                            <td className="px-3 py-2">{b.imported_count}</td>
                            <td className="px-3 py-2">{b.failed_count}</td>
                            <td className="px-3 py-2">
                                <div className="flex gap-2">
                                    <button type="button" onClick={() => router.get(`/settings/balance-migrations/${b.id}`)} className="rounded-lg px-2 py-1 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/20">{t('view')}</button>
                                    {b.failed_count > 0 && <a className="rounded-lg px-2 py-1" href={`/settings/balance-migrations/${b.id}/error-report`}>{t('error_report')}</a>}
                                </div>
                            </td>
                        </tr>
                    ))}
                    {batches.length === 0 && <tr><td className="px-3 py-3" colSpan={7}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
