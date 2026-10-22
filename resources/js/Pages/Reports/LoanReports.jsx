import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Loan reports (Step 20) — eleven registers on the shared engine. */
export default function LoanReports({ reportKey, reports, activeFilters, filters, columns, rows, totalRow, ran, samities, employees, products, frequencies, loanTypes, loanStatuses }) {
    const [values, setValues] = useState(() => {
    const [searching, setSearching] = useState(false);
        const initial = {};
        activeFilters.forEach((f) => { initial[f] = filters[f] ?? ''; });
        return initial;
    });
    const set = (k, v) => setValues((prev) => ({ ...prev, [k]: v }));
    const switchReport = (key) => router.get('/reports/loans', { report: key }, { preserveState: false });
    const run = (e) => {
        e.preventDefault();
        router.get('/reports/loans', { report: reportKey, run: 1, ...values }, { preserveState: true, onStart: () => setSearching(true), onFinish: () => setSearching(false) });
    };
    const pdfUrl = () => {
        const params = new URLSearchParams(Object.entries(values).filter(([, v]) => v !== ''));
        return `/reports/loans/${reportKey}/pdf?${params.toString()}`;
    };
    const has = (f) => activeFilters.includes(f);

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('reports')}</h1>
            <div className="mb-4 flex flex-wrap gap-2">
                {reports.map((r) => (
                    <button key={r.key} type="button" onClick={() => switchReport(r.key)}
                        className={`min-h-[44px] rounded-lg px-3 py-2 text-sm ${r.key === reportKey ? 'bg-emerald-600 text-white' : 'border border-zinc-300 dark:border-zinc-700'}`}>
                        {t(r.title)}
                    </button>
                ))}
            </div>
            <Card className="mb-4">
                <form onSubmit={run} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {has('samity_id') && (
                        <Field label={t('samity')}>
                            <select className={inputCls} value={values.samity_id} onChange={(e) => set('samity_id', e.target.value)}>
                                <option value="">{t('all')}</option>
                                {samities.map((s) => <option key={s.id} value={s.id}>{s.samity_code} — {s.name}</option>)}
                            </select>
                        </Field>
                    )}
                    {has('employee_id') && (
                        <Field label={t('employee')}>
                            <select className={inputCls} value={values.employee_id} onChange={(e) => set('employee_id', e.target.value)}>
                                <option value="">{t('all')}</option>
                                {employees.map((emp) => <option key={emp.id} value={emp.id}>{emp.employee_code} — {emp.name}</option>)}
                            </select>
                        </Field>
                    )}
                    {has('account_type') && (
                        <Field label={t('account_type')}>
                            <select className={inputCls} value={values.account_type} onChange={(e) => set('account_type', e.target.value)}>
                                <option value="">{t('all')}</option>
                                {products.map((p) => <option key={p.id} value={p.id}>{p.code} — {p.name_en}</option>)}
                            </select>
                        </Field>
                    )}
                    {has('member') && (
                        <Field label={t('member_no')}>
                            <input className={inputCls} value={values.member} onChange={(e) => set('member', e.target.value)} />
                        </Field>
                    )}
                    {has('from_date') && (
                        <Field label={t('from_date')}>
                            <input type="date" className={inputCls} value={values.from_date} onChange={(e) => set('from_date', e.target.value)} />
                        </Field>
                    )}
                    {has('to_date') && (
                        <Field label={t('upto_date')}>
                            <input type="date" className={inputCls} value={values.to_date} onChange={(e) => set('to_date', e.target.value)} />
                        </Field>
                    )}
                    {has('recovery_type') && (
                        <Field label={t('recovery_type')}>
                            <select className={inputCls} value={values.recovery_type || 'all'} onChange={(e) => set('recovery_type', e.target.value)}>
                                <option value="all">{t('all')}</option>
                                <option value="cash">{t('cash')}</option>
                                <option value="deposit_transfer">{t('deposit_transfer')}</option>
                            </select>
                        </Field>
                    )}
                    {has('loan_type') && (
                        <Field label={t('loan_type')}>
                            <select className={inputCls} value={values.loan_type || 'all'} onChange={(e) => set('loan_type', e.target.value)}>
                                {loanTypes.map((lt) => <option key={lt} value={lt}>{t(lt)}</option>)}
                            </select>
                        </Field>
                    )}
                    {has('loan_status') && (
                        <Field label={t('status')}>
                            <select className={inputCls} value={values.loan_status} onChange={(e) => set('loan_status', e.target.value)}>
                                <option value="">{t('all')}</option>
                                {loanStatuses.map((s) => <option key={s} value={s}>{s}</option>)}
                            </select>
                        </Field>
                    )}
                    {has('frequency') && (
                        <Field label={t('frequency')}>
                            <select className={inputCls} value={values.frequency} onChange={(e) => set('frequency', e.target.value)}>
                                <option value="">{t('all')}</option>
                                {frequencies.map((fr) => <option key={fr} value={fr}>{fr}</option>)}
                            </select>
                        </Field>
                    )}
                    {has('profit_rate') && (
                        <Field label={t('profit_rate')}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={values.profit_rate} onChange={(e) => set('profit_rate', e.target.value)} />
                        </Field>
                    )}
                    {has('disbursement_day') && (
                        <Field label={t('disbursement_day')}>
                            <input type="number" min="1" max="31" className={inputCls} value={values.disbursement_day} onChange={(e) => set('disbursement_day', e.target.value)} />
                        </Field>
                    )}
                    {has('opening_type') && (
                        <Field label={t('opening_type')}>
                            <select className={inputCls} value={values.opening_type || 'all'} onChange={(e) => set('opening_type', e.target.value)}>
                                <option value="all">{t('all')}</option>
                                <option value="new">{t('new')}</option>
                                <option value="reschedule">{t('reschedule')}</option>
                            </select>
                        </Field>
                    )}
                    {has('month_wise') && (
                        <Field label={t('month_wise_collection')}>
                            <select className={inputCls} value={values.month_wise} onChange={(e) => set('month_wise', e.target.value)}>
                                <option value="">{t('no') || 'No'}</option>
                                <option value="1">{t('yes') || 'Yes'}</option>
                            </select>
                        </Field>
                    )}
                    <div className="flex flex-wrap items-end gap-2">
                        <PrimaryButton type="submit" loading={searching}>{t('run_report')}</PrimaryButton>
                        {ran && <a href={pdfUrl()} target="_blank" rel="noreferrer" className="inline-flex min-h-[44px] items-center rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">{t('print_report')}</a>}
                    </div>
                </form>
            </Card>
            {ran && (
                <Card>
                    <h2 className="mb-3 font-medium">{t(reports.find((r) => r.key === reportKey)?.title)}</h2>
                    <ResponsiveTable head={columns.map((c) => t(c))}>
                        {rows.map((row, i) => (
                            <tr key={i} className="border-b border-zinc-100 dark:border-zinc-800">
                                {row.map((cell, j) => <td key={j} className="px-3 py-2">{cell ?? '—'}</td>)}
                            </tr>
                        ))}
                        {rows.length === 0 && <tr><td className="px-3 py-3" colSpan={Math.max(columns.length, 1)}>{t('no_data_found')}</td></tr>}
                        {totalRow && (
                            <tr className="font-semibold">
                                {totalRow.map((cell, j) => <td key={j} className="px-3 py-2">{j === 0 && cell === '' ? t('total') : cell}</td>)}
                            </tr>
                        )}
                    </ResponsiveTable>
                </Card>
            )}
        </AppLayout>
    );
}
