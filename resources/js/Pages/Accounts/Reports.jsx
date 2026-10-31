import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, SelectInput, TextInput } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

const money = (v) => (v === null || v === undefined || v === '' ? '—' : Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const isMoneyCol = (key) => /amount|balance|debit|credit|opening|closing|receipts|payments|rate/.test(key);

function SectionBlock({ section }) {
    if (section.groups) {
        return (
            <Card className="p-4">
                <h2 className="mb-2 font-semibold">{t('acc_' + section.heading)}</h2>
                {section.groups.map((g) => (
                    <div key={g.group} className="mb-3">
                        <p className="text-sm font-medium text-slate-500">{t('acc_group_' + g.group.toLowerCase())}</p>
                        <table className="w-full text-sm">
                            <tbody>
                                {g.rows.map((r, i) => (
                                    <tr key={i} className="border-t border-slate-100 dark:border-slate-700">
                                        <td className="py-1.5">{r.code} — {r.name}</td><td className="py-1.5 text-right">{money(r.amount)}</td>
                                    </tr>
                                ))}
                                <tr className="font-medium"><td className="py-1.5">{t('subtotal')}</td><td className="py-1.5 text-right">{money(g.total)}</td></tr>
                            </tbody>
                        </table>
                    </div>
                ))}
                {section.total && <p className="text-right font-semibold">{t(section.total[0])}: {money(section.total[1])}</p>}
            </Card>
        );
    }
    return (
        <Card className="p-4">
            <h2 className="mb-2 font-semibold">{t('acc_' + section.heading)}</h2>
            <table className="w-full text-sm">
                <tbody>
                    {section.rows.map((r, i) => (
                        <tr key={i} className="border-t border-slate-100 dark:border-slate-700">
                            <td className="py-1.5">{r[0]}</td><td className="py-1.5 text-right">{money(r[1])}</td>
                        </tr>
                    ))}
                    {section.total && (
                        <tr className="font-semibold"><td className="py-1.5">{t(section.total[0])}</td><td className="py-1.5 text-right">{money(section.total[1])}</td></tr>
                    )}
                </tbody>
            </table>
        </Card>
    );
}

export default function Reports({
    reportKey, reports = [], activeFilters = [], filters = {}, columns = [], rows = [], totalRow = null,
    sections = [], summary = {}, ran = false, branches = [], ledgers = [], depositAccounts = [], loanAccounts = [],
}) {
    const [f, setF] = useState({
        branch_id: filters.branch_id ?? '', from: filters.from ?? '', to: filters.to ?? '', date: filters.date ?? '',
        ledger_id: filters.ledger_id ?? '', account_type: filters.account_type ?? '', account_id: filters.account_id ?? '', member: filters.member ?? '',
    });
    const has = (name) => activeFilters.includes(name);
    const run = (e) => {
        e.preventDefault();
        router.get('/accounts/reports', { report: reportKey, run: 1, ...f }, { preserveState: true });
    };
    const pdfHref = `/accounts/reports/pdf?report=${encodeURIComponent(reportKey)}&run=1&${new URLSearchParams(Object.entries(f).filter(([, v]) => v !== '' && v !== null)).toString()}`;

    return (
        <AppLayout>
            <div className="mx-auto max-w-6xl space-y-4 p-4">
                <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t(reports.find((r) => r.key === reportKey)?.title ?? 'acc_reports')}</h1>
                <div className="flex flex-wrap gap-2">
                    {reports.map((r) => (
                        <Link key={r.key} href={`/accounts/reports?report=${r.key}`}
                            className={`rounded px-3 py-1.5 text-sm font-medium ${r.key === reportKey ? 'bg-cyan-600 text-white' : 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200'}`}>
                            {t(r.title)}
                        </Link>
                    ))}
                </div>
                <Card className="p-4">
                    <form onSubmit={run} className="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        {has('branch') && (
                            <Field label={t('branch')}>
                                <SelectInput value={f.branch_id} onChange={(e) => setF({ ...f, branch_id: e.target.value })}>
                                    <option value="">{t('acc_active_branch')}</option>
                                    <option value="all">{t('acc_all_branches')}</option>
                                    {branches.map((b) => <option key={b.id} value={b.id}>{b.code} — {b.name}</option>)}
                                </SelectInput>
                            </Field>
                        )}
                        {has('from') && <Field label={t('from_date')}><TextInput type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} /></Field>}
                        {has('to') && <Field label={t('to_date')}><TextInput type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} /></Field>}
                        {has('date') && <Field label={t('date')}><TextInput type="date" value={f.date} onChange={(e) => setF({ ...f, date: e.target.value })} /></Field>}
                        {has('ledger') && (
                            <Field label={t('ledger')} required>
                                <SelectInput value={f.ledger_id} onChange={(e) => setF({ ...f, ledger_id: e.target.value })}>
                                    <option value="">{t('select')}</option>
                                    {ledgers.map((l) => <option key={l.id} value={l.id}>{l.label}</option>)}
                                </SelectInput>
                            </Field>
                        )}
                        {has('account') && (
                            <>
                                <Field label={t('acc_account_kind')} required>
                                    <SelectInput value={f.account_type} onChange={(e) => setF({ ...f, account_type: e.target.value, account_id: '' })}>
                                        <option value="">{t('select')}</option>
                                        <option value="deposit">{t('deposit_account')}</option>
                                        <option value="loan">{t('loan_account')}</option>
                                        <option value="share">{t('acc_share_account')}</option>
                                    </SelectInput>
                                </Field>
                                <Field label={t('account')} required>
                                    {f.account_type === 'share' ? (
                                        <TextInput type="number" min="1" value={f.account_id} onChange={(e) => setF({ ...f, account_id: e.target.value })} placeholder={t('member_id')} />
                                    ) : (
                                        <SelectInput value={f.account_id} onChange={(e) => setF({ ...f, account_id: e.target.value })}>
                                            <option value="">{t('select')}</option>
                                            {(f.account_type === 'loan' ? loanAccounts : depositAccounts).map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}
                                        </SelectInput>
                                    )}
                                </Field>
                            </>
                        )}
                        {has('member') && (
                            <Field label={t('member_no')} required>
                                <TextInput value={f.member} onChange={(e) => setF({ ...f, member: e.target.value })} placeholder="102000001" />
                            </Field>
                        )}
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
                                <p className="font-semibold">{typeof v === 'boolean' ? (v ? '✓' : '✗') : (/^-?\d+(\.\d+)?$/.test(String(v)) ? money(v) : v)}</p>
                            </Card>
                        ))}
                    </div>
                )}

                {ran && columns.length > 0 && (
                    <Card className="p-0">
                        <ResponsiveTable head={columns.map((c) => t(c))}>
                            {rows.map((row, i) => (
                                <tr key={i} className="border-t border-slate-100 dark:border-slate-700">
                                    {row.map((cell, j) => (
                                        <td key={j} className={`px-3 py-2 ${isMoneyCol(columns[j]) ? 'text-right' : ''}`}>{isMoneyCol(columns[j]) ? money(cell) : cell}</td>
                                    ))}
                                </tr>
                            ))}
                            {totalRow && (
                                <tr className="border-t-2 border-slate-200 bg-slate-50 font-semibold dark:border-slate-600 dark:bg-slate-800">
                                    {totalRow.map((cell, j) => (
                                        <td key={j} className={`px-3 py-2 ${isMoneyCol(columns[j]) ? 'text-right' : ''}`}>{isMoneyCol(columns[j]) && cell !== '' ? money(cell) : cell}</td>
                                    ))}
                                </tr>
                            )}
                            {rows.length === 0 && (
                                <tr><td colSpan={columns.length} className="px-3 py-6 text-center text-slate-500">{t('no_data')}</td></tr>
                            )}
                        </ResponsiveTable>
                    </Card>
                )}

                {ran && sections.map((s, i) => <SectionBlock key={i} section={s} />)}
            </div>
        </AppLayout>
    );
}
