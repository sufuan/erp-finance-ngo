import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Deposit reports (Step 14) — one engine, twelve registers. */
export default function DepositReports({ reportKey, reports, activeFilters, filters, columns, rows, totalRow, ran, samities, employees, products, accountStatuses }) {
    const [searching, setSearching] = useState(false);
    const [values, setValues] = useState(() => {
        const initial = {};
        activeFilters.forEach((f) => { initial[f] = filters[f] ?? ''; });
        return initial;
    });
    const set = (k, v) => setValues((prev) => ({ ...prev, [k]: v }));
    const switchReport = (key) => router.get('/reports/deposits', { report: key }, { preserveState: false });
    const run = (e) => {
        e.preventDefault();
        router.get('/reports/deposits', { report: reportKey, run: 1, ...values }, { preserveState: true, onStart: () => setSearching(true), onFinish: () => setSearching(false) });
    };
    const pdfUrl = () => {
        const params = new URLSearchParams(Object.entries(values).filter(([, v]) => v !== ''));
        return `/reports/deposits/${reportKey}/pdf?${params.toString()}`;
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
                    {has('member') && (
                        <Field label={t('member_no')}>
                            <input className={inputCls} value={values.member} onChange={(e) => set('member', e.target.value)} />
                        </Field>
                    )}
                    {has('from_date') && (
                        <Field label={t('from_date')} required>
                            <input type="date" className={inputCls} value={values.from_date} onChange={(e) => set('from_date', e.target.value)} required />
                        </Field>
                    )}
                    {has('to_date') && (
                        <Field label={t('upto_date')} required>
                            <input type="date" className={inputCls} value={values.to_date} onChange={(e) => set('to_date', e.target.value)} required />
                        </Field>
                    )}
                    {has('account_status') && (
                        <Field label={t('account_status')}>
                            <select className={inputCls} value={values.account_status || 'all'} onChange={(e) => set('account_status', e.target.value)}>
                                {accountStatuses.map((s) => <option key={s} value={s}>{t(`account_status_${s}`)}</option>)}
                            </select>
                        </Field>
                    )}
                    {has('year') && (
                        <Field label={t('transaction_year')} required>
                            <input type="number" min="2000" max="2100" className={inputCls} value={values.year} onChange={(e) => set('year', e.target.value)} required />
                        </Field>
                    )}
                    {has('profit_type') && (
                        <Field label={t('profit_type')}>
                            <select className={inputCls} value={values.profit_type} onChange={(e) => set('profit_type', e.target.value)}>
                                <option value="">{t('all')}</option>
                                {products.map((p) => <option key={p.id} value={p.code}>{p.code}</option>)}
                            </select>
                        </Field>
                    )}
                    <div className="flex items-end gap-2">
                        <PrimaryButton type="submit" loading={searching}>{t('search')}</PrimaryButton>
                        {ran && (
                            <a href={pdfUrl()} target="_blank" rel="noreferrer" className="inline-flex min-h-[44px] items-center rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium dark:border-zinc-700">{t('print_report')}</a>
                        )}
                    </div>
                </form>
            </Card>

            {ran && (
                <Card>
                    <h2 className="mb-3 text-base font-semibold">{t(reports.find((r) => r.key === reportKey)?.title ?? '')}</h2>
                    <ResponsiveTable head={columns.map((c) => t(c))}>
                        {rows.map((row, i) => (
                            <tr key={i} className="border-b border-zinc-100 dark:border-zinc-800">
                                {row.map((cell, j) => <td key={j} className="px-3 py-2">{cell}</td>)}
                            </tr>
                        ))}
                        {totalRow && (
                            <tr className="font-semibold">
                                {totalRow.map((cell, j) => <td key={j} className="px-3 py-2">{j === 0 ? t('total') : cell}</td>)}
                            </tr>
                        )}
                        {rows.length === 0 && <tr><td className="px-3 py-3" colSpan={Math.max(columns.length, 1)}>{t('no_data_found')}</td></tr>}
                    </ResponsiveTable>
                </Card>
            )}
        </AppLayout>
    );
}
