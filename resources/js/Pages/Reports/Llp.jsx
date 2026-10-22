import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Loan Loss Provisioning (Step 20) — compute, review, post the journal. */
export default function Llp({ filters, ran, rows, totals, provision_balance, runs, products, samities, employees, frequencies }) {
    const runSearch = (e) => {
        e.preventDefault();
        router.get('/reports/llp', { run: 1, ...Object.fromEntries(new FormData(e.target).entries()) }, { preserveState: true, onStart: () => setSearching(true), onFinish: () => setSearching(false) });
    };
    const poster = useForm({ ...filters });
    const [searching, setSearching] = useState(false);
    const postRun = () => poster.post('/reports/llp/post');

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('loan_loss_provision')}</h1>
            <Card className="mb-4">
                <form onSubmit={runSearch} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label={t('account_type')}>
                        <select name="account_type" className={inputCls} defaultValue={filters.account_type ?? ''}>
                            <option value="">{t('all')}</option>
                            {products.map((p) => <option key={p.id} value={p.id}>{p.code} — {p.name_en}</option>)}
                        </select>
                    </Field>
                    <Field label={t('samity')}>
                        <select name="samity_id" className={inputCls} defaultValue={filters.samity_id ?? ''}>
                            <option value="">{t('all')}</option>
                            {samities.map((s) => <option key={s.id} value={s.id}>{s.samity_code} — {s.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('employee')}>
                        <select name="employee_id" className={inputCls} defaultValue={filters.employee_id ?? ''}>
                            <option value="">{t('all')}</option>
                            {employees.map((emp) => <option key={emp.id} value={emp.id}>{emp.employee_code} — {emp.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('member_no')}>
                        <input name="member" className={inputCls} defaultValue={filters.member ?? ''} />
                    </Field>
                    <Field label={t('frequency')}>
                        <select name="frequency" className={inputCls} defaultValue={filters.frequency ?? ''}>
                            <option value="">{t('all')}</option>
                            {frequencies.map((fr) => <option key={fr} value={fr}>{fr}</option>)}
                        </select>
                    </Field>
                    <Field label={t('report_date')} required>
                        <input type="date" name="report_date" required className={inputCls} defaultValue={filters.report_date} />
                    </Field>
                    <div className="flex flex-wrap items-end gap-2">
                        <PrimaryButton type="submit" loading={searching}>{t('run_report')}</PrimaryButton>
                        {ran && <PrimaryButton type="button" onClick={postRun} loading={poster.processing}>{t('post_llp_run')}</PrimaryButton>}
                        <button type="button" onClick={() => window.print()} className="inline-flex min-h-[44px] items-center rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">{t('print_report')}</button>
                    </div>
                </form>
                <p className="mt-3 text-sm text-zinc-500">{t('provision_balance')}: <span className="font-medium text-zinc-800 dark:text-zinc-100">{provision_balance}</span></p>
            </Card>
            {ran && (
                <Card className="mb-4">
                    <ResponsiveTable head={[t('loan_number'), t('member_name'), t('samity'), t('classification'), t('overdue_days'), t('gross_outstanding'), t('compulsory_savings'), t('provision_basis'), t('coverage'), t('required_llp')]}>
                        {rows.map((r) => (
                            <tr key={r.loan_id} className="border-b border-zinc-100 dark:border-zinc-800">
                                <td className="px-3 py-2">{r.loan_number}</td>
                                <td className="px-3 py-2">{r.member ?? '—'}</td>
                                <td className="px-3 py-2">{r.samity ?? '—'}</td>
                                <td className="px-3 py-2">{t(r.bucket)}</td>
                                <td className="px-3 py-2">{r.overdue_days}</td>
                                <td className="px-3 py-2">{r.gross}</td>
                                <td className="px-3 py-2">{r.savings}</td>
                                <td className="px-3 py-2">{r.basis}</td>
                                <td className="px-3 py-2">{r.rate}%</td>
                                <td className="px-3 py-2">{r.required}</td>
                            </tr>
                        ))}
                        {rows.length === 0 && <tr><td className="px-3 py-3" colSpan={10}>{t('no_data_found')}</td></tr>}
                        <tr className="font-semibold">
                            <td className="px-3 py-2" colSpan={7}>{t('total')}</td>
                            <td className="px-3 py-2">{totals.outstanding}</td>
                            <td className="px-3 py-2"></td>
                            <td className="px-3 py-2">{totals.required}</td>
                        </tr>
                    </ResponsiveTable>
                </Card>
            )}
            <Card>
                <h2 className="mb-3 font-medium">{t('history')}</h2>
                <ResponsiveTable head={[t('report_date'), t('account_type'), t('provision_basis'), t('required_llp'), t('actual_llp'), t('coverage')]}>
                    {runs.map((r) => (
                        <tr key={r.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{r.report_date}</td>
                            <td className="px-3 py-2">{r.account_type}</td>
                            <td className="px-3 py-2">{r.outstanding}</td>
                            <td className="px-3 py-2">{r.required_llp}</td>
                            <td className="px-3 py-2">{r.actual_llp}</td>
                            <td className="px-3 py-2">{r.coverage}%</td>
                        </tr>
                    ))}
                    {runs.length === 0 && <tr><td className="px-3 py-3" colSpan={6}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
