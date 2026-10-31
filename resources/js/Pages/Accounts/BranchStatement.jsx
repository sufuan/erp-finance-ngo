import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, SelectInput, TextInput } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

export default function BranchStatement({ statement = null, from = '', to = '', branchId = null, branches = [], requests = [] }) {
    const [f, setF] = useState({ branch_id: branchId ? String(branchId) : '', from, to });
    const apply = (e) => {
        e.preventDefault();
        router.get('/accounts/branch-statement', f, { preserveState: true });
    };

    return (
        <AppLayout>
            <div className="mx-auto max-w-6xl space-y-4 p-4">
                <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t('acc_branch_transaction_statement')}</h1>
                <Card className="p-4">
                    <form onSubmit={apply} className="grid gap-3 sm:grid-cols-4">
                        <Field label={t('branch')}>
                            <SelectInput value={f.branch_id} onChange={(e) => setF({ ...f, branch_id: e.target.value })}>
                                <option value="">{t('acc_active_branch')}</option>
                                {branches.map((b) => <option key={b.id} value={b.id}>{b.code} — {b.name}</option>)}
                            </SelectInput>
                        </Field>
                        <Field label={t('from_date')}><TextInput type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} /></Field>
                        <Field label={t('to_date')}><TextInput type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} /></Field>
                        <div className="flex items-end"><PrimaryButton type="submit">{t('acc_run_report')}</PrimaryButton></div>
                    </form>
                </Card>
                {statement && (
                    <>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Card className="p-4"><p className="text-sm text-slate-500">{t('acc_opening_balance')}</p><p className="text-lg font-semibold">{Number(statement.opening).toLocaleString()}</p></Card>
                            <Card className="p-4"><p className="text-sm text-slate-500">{t('acc_closing_balance')}</p><p className="text-lg font-semibold">{Number(statement.closing).toLocaleString()}</p></Card>
                        </div>
                        <Card className="p-0">
                            <ResponsiveTable head={[t('date'), t('acc_voucher_no'), t('particulars'), t('debit'), t('credit'), t('balance')]}>
                                {statement.rows.map((r, i) => (
                                    <tr key={i} className="border-t border-slate-100 dark:border-slate-700">
                                        <td className="px-3 py-2">{r.date}</td>
                                        <td className="px-3 py-2">{r.voucher_no}</td>
                                        <td className="px-3 py-2">{r.particulars}</td>
                                        <td className="px-3 py-2 text-right">{Number(r.debit) > 0 ? Number(r.debit).toLocaleString() : '—'}</td>
                                        <td className="px-3 py-2 text-right">{Number(r.credit) > 0 ? Number(r.credit).toLocaleString() : '—'}</td>
                                        <td className="px-3 py-2 text-right">{Number(r.balance).toLocaleString()}</td>
                                    </tr>
                                ))}
                                {statement.rows.length === 0 && (
                                    <tr><td colSpan={6} className="px-3 py-6 text-center text-slate-500">{t('no_data')}</td></tr>
                                )}
                            </ResponsiveTable>
                        </Card>
                        {requests.length > 0 && (
                            <Card className="p-0">
                                <ResponsiveTable head={[t('acc_request_no'), t('acc_transfer_kind'), t('acc_from_branch'), t('acc_to_branch'), t('amount'), t('status')]}>
                                    {requests.map((r) => (
                                        <tr key={r.id} className="border-t border-slate-100 dark:border-slate-700">
                                            <td className="px-3 py-2 font-medium">{r.request_no}</td>
                                            <td className="px-3 py-2">{r.kind}</td>
                                            <td className="px-3 py-2">{r.from_branch}</td>
                                            <td className="px-3 py-2">{r.to_branch}</td>
                                            <td className="px-3 py-2 text-right">{Number(r.amount).toLocaleString()}</td>
                                            <td className="px-3 py-2">{r.status}</td>
                                        </tr>
                                    ))}
                                </ResponsiveTable>
                            </Card>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
