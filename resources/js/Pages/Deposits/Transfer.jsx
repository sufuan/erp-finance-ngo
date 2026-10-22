import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Deposit transfer (Step 11) — account to account, no cash leg. */
export default function DepositTransfer({ fromMember, toMember, fromQuery, toQuery, fromAccounts, toAccounts, transfers, businessDate }) {
    const form = useForm({ from_account_id: '', to_account_id: '', amount: '', document_number: '', comments: '' });
    const [searching, setSearching] = useState(false);
    const fromAccount = fromAccounts.find((a) => String(a.id) === String(form.data.from_account_id));
    const toAccount = toAccounts.find((a) => String(a.id) === String(form.data.to_account_id));

    const lookup = (param) => (e) => {
        e.preventDefault();
        const value = e.target.elements[param].value;
        router.get('/deposits/transfer', { from_member: param === 'from_member' ? value : fromQuery, to_member: param === 'to_member' ? value : toQuery }, { preserveState: true, onStart: () => setSearching(true), onFinish: () => setSearching(false) });
    };
    const submit = (e) => { e.preventDefault(); form.post('/deposits/transfer', { preserveScroll: true }); };
    const cancelRow = (id) => router.post(`/deposits/transfers/${id}/cancel`, {}, { preserveScroll: true });

    const memberPicker = (param, member, accounts, key, label) => (
        <div className="grid gap-3">
            <form onSubmit={lookup(param)} className="flex items-end gap-2">
                <div className="flex-1">
                    <Field label={t(label)}>
                        <input name={param} className={inputCls} defaultValue={param === 'from_member' ? fromQuery : toQuery} />
                    </Field>
                </div>
                <PrimaryButton type="submit" loading={searching}>{t('search')}</PrimaryButton>
            </form>
            {member && <div className="text-sm text-zinc-500">{member.member_no} — {member.name}</div>}
            <Field label={t('deposit_account')}>
                <select className={inputCls} value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)}>
                    <option value="">—</option>
                    {accounts.map((a) => <option key={a.id} value={a.id}>{a.account_number} · {a.product} · {a.current_balance}</option>)}
                </select>
            </Field>
        </div>
    );

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('deposit_transfer')}</h1>
            <Card className="mb-4">
                <div className="grid gap-6 lg:grid-cols-2">
                    <div>
                        <h2 className="mb-2 font-medium">{t('from_customer')}</h2>
                        {memberPicker('from_member', fromMember, fromAccounts, 'from_account_id', 'member_no')}
                        {fromAccount && <div className="mt-2 text-sm">{t('account_balance')}: {fromAccount.current_balance}</div>}
                    </div>
                    <div>
                        <h2 className="mb-2 font-medium">{t('to_customer')}</h2>
                        {memberPicker('to_member', toMember, toAccounts, 'to_account_id', 'member_no')}
                        {toAccount && <div className="mt-2 text-sm">{t('account_balance')}: {toAccount.current_balance}</div>}
                    </div>
                </div>
                <form onSubmit={submit} className="mt-6 grid gap-4 sm:grid-cols-2">
                    <Field label={t('transfer_date')}>
                        <input type="date" className={inputCls} value={businessDate} readOnly />
                    </Field>
                    <Field label={t('transfer_amount')} required error={form.errors.amount}>
                        <input type="number" step="0.01" min="0" className={inputCls} value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} required />
                    </Field>
                    <Field label={t('document_number')} error={form.errors.document_number}>
                        <input className={inputCls} value={form.data.document_number} onChange={(e) => form.setData('document_number', e.target.value)} />
                    </Field>
                    <Field label={t('comments')} required error={form.errors.comments}>
                        <input className={inputCls} value={form.data.comments} onChange={(e) => form.setData('comments', e.target.value)} required />
                    </Field>
                    <div className="sm:col-span-2">
                        <PrimaryButton type="submit" loading={form.processing}>{t('deposit_transfer')}</PrimaryButton>
                    </div>
                </form>
            </Card>

            <Card>
                <ResponsiveTable head={[t('date'), t('from_account'), t('to_account'), t('amount'), t('document_number'), t('comments'), t('cancelled_by'), t('actions')]}>
                    {transfers.map((tr) => (
                        <tr key={tr.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{tr.transfer_date}</td>
                            <td className="px-3 py-2">{tr.from_account}</td>
                            <td className="px-3 py-2">{tr.to_account}</td>
                            <td className="px-3 py-2">{tr.amount}</td>
                            <td className="px-3 py-2">{tr.document_number ?? '—'}</td>
                            <td className="px-3 py-2">{tr.comments ?? '—'}</td>
                            <td className="px-3 py-2">{tr.cancel_at ? `${tr.cancel_by ?? ''} · ${String(tr.cancel_at).slice(0, 10)}` : '—'}</td>
                            <td className="px-3 py-2">
                                {!tr.cancel_at && <button type="button" onClick={() => cancelRow(tr.id)} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('cancel')}</button>}
                            </td>
                        </tr>
                    ))}
                    {transfers.length === 0 && <tr><td className="px-3 py-3" colSpan={8}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
