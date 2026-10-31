import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, SelectInput, TextInput } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

const money = (v) => (v === null || v === undefined || v === '' ? '—' : Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const HEADINGS = { assets: 'acc_assets', liabilities: 'acc_liabilities', equity_fund: 'acc_equity_fund', income: 'acc_income', expenses: 'acc_expenses' };

export default function Statements({ statementKey, statements = [], filters = {}, ran = false, sections = [], summary = {}, columnsTable = null, branches = [] }) {
    const [f, setF] = useState({ branch_id: filters.branch_id ?? '', from: filters.from ?? '', to: filters.to ?? '' });
    const run = (e) => {
        e.preventDefault();
        router.get('/accounts/statements', { statement: statementKey, run: 1, ...f }, { preserveState: true });
    };
    const pdfHref = `/accounts/reports/pdf?report=${encodeURIComponent('statement:' + statementKey)}&run=1&${new URLSearchParams(Object.entries(f).filter(([, v]) => v !== '')).toString()}`;

    return (
        <AppLayout>
            <div className="mx-auto max-w-6xl space-y-4 p-4">
                <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t(statements.find((s) => s.key === statementKey)?.title ?? 'acc_statements_analysis')}</h1>
                <div className="flex flex-wrap gap-2">
                    {statements.map((s) => (
                        <Link key={s.key} href={`/accounts/statements?statement=${s.key}`}
                            className={`rounded px-3 py-1.5 text-sm font-medium ${s.key === statementKey ? 'bg-cyan-600 text-white' : 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200'}`}>
                            {t(s.title)}
                        </Link>
                    ))}
                </div>
                <Card className="p-4">
                    <form onSubmit={run} className="grid gap-3 sm:grid-cols-4">
                        <Field label={t('branch')}>
                            <SelectInput value={f.branch_id} onChange={(e) => setF({ ...f, branch_id: e.target.value })}>
                                <option value="">{t('acc_active_branch')}</option>
                                <option value="all">{t('acc_all_branches')}</option>
                                {branches.map((b) => <option key={b.id} value={b.id}>{b.code} — {b.name}</option>)}
                            </SelectInput>
                        </Field>
                        <Field label={t('from_date')}><TextInput type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} /></Field>
                        <Field label={t('to_date')}><TextInput type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} /></Field>
                        <div className="flex items-end gap-2">
                            <PrimaryButton type="submit">{t('acc_run_report')}</PrimaryButton>
                            {ran && <a href={pdfHref} target="_blank" rel="noreferrer" className="rounded bg-slate-700 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">{t('print_report')}</a>}
                        </div>
                    </form>
                </Card>

                {ran && summary && Object.keys(summary).length > 0 && (
                    <div className="flex flex-wrap gap-3">
                        {Object.entries(summary).map(([k, v]) => (
                            <Card key={k} className="px-4 py-3">
                                <p className="text-xs text-slate-500">{t('acc_' + k)}</p>
                                <p className="font-semibold">{typeof v === 'boolean' ? (v ? '✓ ' + t('acc_balanced') : '✗') : money(v)}</p>
                            </Card>
                        ))}
                    </div>
                )}

                {ran && sections.map((s, i) => (
                    <Card key={i} className="p-4">
                        <h2 className="mb-2 font-semibold">{t(HEADINGS[s.heading] ?? s.heading)}</h2>
                        <table className="w-full text-sm">
                            <tbody>
                                {s.rows.map((r, j) => (
                                    <tr key={j} className="border-t border-slate-100 dark:border-slate-700">
                                        <td className="py-1.5">{r[0]}</td><td className="py-1.5 text-right">{money(r[1])}</td>
                                    </tr>
                                ))}
                                {s.rows.length === 0 && <tr><td className="py-3 text-center text-slate-500">{t('no_data')}</td></tr>}
                                {s.total && (
                                    <tr className="font-semibold"><td className="py-1.5">{t(s.total[0])}</td><td className="py-1.5 text-right">{money(s.total[1])}</td></tr>
                                )}
                            </tbody>
                        </table>
                    </Card>
                ))}

                {ran && columnsTable && (
                    <Card className="p-0">
                        <ResponsiveTable head={columnsTable.columns.map((c) => (c === 'particulars' || c === 'total' ? t(c === 'total' ? 'total' : 'particulars') : c))}>
                            {columnsTable.rows.map((row, i) => (
                                <tr key={i} className="border-t border-slate-100 dark:border-slate-700">
                                    {row.map((cell, j) => (
                                        <td key={j} className={`px-3 py-2 ${j > 0 ? 'text-right' : ''}`}>{j > 0 ? money(cell) : cell}</td>
                                    ))}
                                </tr>
                            ))}
                        </ResponsiveTable>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
