import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from './ui';
import AppLayout from '../Layouts/AppLayout';
import { t } from '../lib/i18n';

/**
 * Shared report engine UI for the Collection module (Steps 22–23):
 * report switcher chips, filter panel, results table, Print Report
 * (PDF). Sheets, reports and balance pages differ only by base path,
 * heading and the report list the server passes in.
 */
export default function CollectionReportEngine({ basePath, headingKey, reportKey, reports, activeFilters, filters, columns, rows, totalRow, ran, samities, employees }) {
    const [searching, setSearching] = useState(false);
    const [values, setValues] = useState(() => {
        const initial = {};
        activeFilters.forEach((f) => { initial[f] = filters[f] ?? ''; });
        return initial;
    });
    const set = (k, v) => setValues((prev) => ({ ...prev, [k]: v }));
    const switchReport = (key) => router.get(basePath, { report: key }, { preserveState: false });
    const run = (e) => {
        e.preventDefault();
        router.get(basePath, { report: reportKey, run: 1, ...values }, { preserveState: true, onStart: () => setSearching(true), onFinish: () => setSearching(false) });
    };
    const pdfUrl = () => {
        const params = new URLSearchParams(Object.entries(values).filter(([, v]) => v !== ''));
        return `/collection/reports/${reportKey}/pdf?${params.toString()}`;
    };
    const has = (f) => activeFilters.includes(f);

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t(headingKey)}</h1>
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
                        <Field label={t('from_date')}>
                            <input type="date" className={inputCls} value={values.from_date} onChange={(e) => set('from_date', e.target.value)} />
                        </Field>
                    )}
                    {has('to_date') && (
                        <Field label={t('upto_date')}>
                            <input type="date" className={inputCls} value={values.to_date} onChange={(e) => set('to_date', e.target.value)} />
                        </Field>
                    )}
                    {has('report_date') && (
                        <Field label={t('report_date')}>
                            <input type="date" className={inputCls} value={values.report_date} onChange={(e) => set('report_date', e.target.value)} />
                        </Field>
                    )}
                    {has('year') && (
                        <Field label={t('year')}>
                            <input type="number" className={inputCls} value={values.year} onChange={(e) => set('year', e.target.value)} />
                        </Field>
                    )}
                    {has('sheet_type') && (
                        <Field label={t('sheet_type')}>
                            <select className={inputCls} value={values.sheet_type || 'blank'} onChange={(e) => set('sheet_type', e.target.value)}>
                                <option value="blank">{t('blank_sheet')}</option>
                                <option value="target">{t('target_sheet')}</option>
                                <option value="with_data">{t('with_data_sheet')}</option>
                            </select>
                        </Field>
                    )}
                    {has('reporting_type') && (
                        <Field label={t('reporting_type')}>
                            <select className={inputCls} value={values.reporting_type || 'all'} onChange={(e) => set('reporting_type', e.target.value)}>
                                <option value="all">{t('all_collection')}</option>
                                <option value="overdue">{t('overdue')}</option>
                                <option value="advance">{t('advance')}</option>
                                <option value="regular">{t('regular')}</option>
                            </select>
                        </Field>
                    )}
                    {has('report_group') && (
                        <Field label={t('report_group')}>
                            <select className={inputCls} value={values.report_group || 'center'} onChange={(e) => set('report_group', e.target.value)}>
                                <option value="center">{t('center')}</option>
                                <option value="member">{t('member')}</option>
                                <option value="branch">{t('branch')}</option>
                                <option value="employee">{t('employee')}</option>
                            </select>
                        </Field>
                    )}
                    <div className="flex items-end gap-2">
                        <PrimaryButton type="submit" loading={searching}>{t('generate_report')}</PrimaryButton>
                        {ran && (
                            <a href={pdfUrl()} target="_blank" rel="noreferrer" className="inline-flex min-h-[44px] items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                {t('print_report')}
                            </a>
                        )}
                    </div>
                </form>
            </Card>

            {ran && (
                <Card>
                    <h2 className="mb-3 font-medium">{t(reportKey === 'collection_sheet' || reportKey?.includes('sheet') ? reportKey : (reports.find((r) => r.key === reportKey)?.title ?? 'reports'))}</h2>
                    <ResponsiveTable head={columns.map((c) => t(c))}>
                        {rows.map((row, i) => (
                            <tr key={i} className="border-b border-zinc-100 dark:border-zinc-800">
                                {row.map((cell, j) => <td key={j} className="px-3 py-2">{cell}</td>)}
                            </tr>
                        ))}
                        {totalRow && (
                            <tr className="font-semibold">
                                {totalRow.map((cell, j) => <td key={j} className="px-3 py-2">{j === 0 && cell === '' ? t('total') : cell}</td>)}
                            </tr>
                        )}
                    </ResponsiveTable>
                    {rows.length === 0 && <p className="py-4 text-sm text-zinc-500">{t('no_data_found')}</p>}
                </Card>
            )}
        </AppLayout>
    );
}
