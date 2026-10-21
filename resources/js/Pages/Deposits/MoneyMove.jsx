import { router, useForm } from '@inertiajs/react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/**
 * Shared receive/payment screen (Step 11): member lookup, account select,
 * ACCOUNT SNAPSHOT panel, amount form, and the account's recent history.
 */
export default function MoneyMove({ mode, member, memberQuery, accounts, snapshot, history, accountId, businessDate, preset }) {
    const isReceive = mode === 'receive';
    const form = useForm({ account_id: accountId ?? '', amount: '', document_number: '', comments: '' });

    const lookup = (e) => {
        e.preventDefault();
        const needle = e.target.elements.member.value;
        router.get(`/deposits/${mode}`, { member: needle, ...(preset ? { product: preset } : {}) }, { preserveState: true });
    };
    const pickAccount = (id) => {
        form.setData('account_id', id);
        router.get(`/deposits/${mode}`, { member: memberQuery, account_id: id, ...(preset ? { product: preset } : {}) }, { preserveState: true, preserveScroll: true });
    };
    const submit = (e) => { e.preventDefault(); form.post(`/deposits/${mode}`, { preserveScroll: true }); };
    const cancelRow = (id) => router.post(`/deposits/transactions/${id}/cancel`, {}, { preserveScroll: true });

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t(isReceive ? 'deposit_receive' : 'deposit_payment')}</h1>

            <Card className="mb-4">
                <form onSubmit={lookup} className="flex flex-wrap items-end gap-3">
                    <div className="min-w-56 flex-1">
                        <Field label={t('member_no')}>
                            <input name="member" className={inputCls} defaultValue={memberQuery} />
                        </Field>
                    </div>
                    <PrimaryButton type="submit">{t('search')}</PrimaryButton>
                </form>
                {member && (
                    <div className="mt-3">
                        <Field label={t('deposit_account')}>
                            <select className={inputCls} value={form.data.account_id} onChange={(e) => pickAccount(e.target.value)}>
                                <option value="">—</option>
                                {accounts.map((a) => <option key={a.id} value={a.id}>{a.account_number} · {a.product} · {a.current_balance}</option>)}
                            </select>
                        </Field>
                    </div>
                )}
            </Card>

            {snapshot?.account && (
                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                            <Field label={`${t(isReceive ? 'deposit_amount' : 'payment_amount')} *`} error={form.errors.amount}>
                                <input type="number" step="0.01" min="0" className={inputCls} value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} required />
                            </Field>
                            <Field label={t(isReceive ? 'deposit_date' : 'payment_date')}>
                                <input type="date" className={inputCls} value={businessDate} readOnly />
                            </Field>
                            <Field label={t('account_balance')}>
                                <input className={inputCls} value={snapshot.account.current_balance} readOnly />
                            </Field>
                            <Field label={t('document_number')} error={form.errors.document_number}>
                                <input className={inputCls} value={form.data.document_number} onChange={(e) => form.setData('document_number', e.target.value)} />
                            </Field>
                            <Field label={t('comments')} error={form.errors.comments}>
                                <textarea className={inputCls} rows={2} value={form.data.comments} onChange={(e) => form.setData('comments', e.target.value)} />
                            </Field>
                            <div className="flex items-end">
                                <PrimaryButton type="submit" disabled={form.processing}>{t(isReceive ? 'deposit_receive' : 'deposit_payment')}</PrimaryButton>
                            </div>
                        </form>
                    </Card>
                    <Card>
                        <h2 className="mb-3 font-medium">{t('account_snapshot')}</h2>
                        <dl className="space-y-2 text-sm">
                            <div><dt className="text-zinc-500">{t('full_name')}</dt><dd className="font-medium">{snapshot.name}</dd></div>
                            <div><dt className="text-zinc-500">{t('member_no')}</dt><dd>{snapshot.member_no}</dd></div>
                            <div><dt className="text-zinc-500">{t('samity')}</dt><dd>{snapshot.samity ?? '—'}</dd></div>
                            <div><dt className="text-zinc-500">{t('account_number')}</dt><dd>{snapshot.account.account_number}</dd></div>
                            <div><dt className="text-zinc-500">{t('account_type')}</dt><dd>{snapshot.account.product}</dd></div>
                            <div><dt className="text-zinc-500">{t('total_credit')}</dt><dd>{snapshot.account.total_credit}</dd></div>
                            <div><dt className="text-zinc-500">{t('total_debit')}</dt><dd>{snapshot.account.total_debit}</dd></div>
                            <div><dt className="text-zinc-500">{t('current_balance')}</dt><dd className="font-medium">{snapshot.account.current_balance}</dd></div>
                        </dl>
                    </Card>
                </div>
            )}

            {history.length > 0 && (
                <Card className="mt-4">
                    <h2 className="mb-3 font-medium">{t('history')}</h2>
                    <ResponsiveTable head={[t('date'), t('type'), t('amount'), t('document_number'), t('comments'), t('actions')]}>
                        {history.map((h) => (
                            <tr key={h.id} className="border-b border-zinc-100 dark:border-zinc-800">
                                <td className="px-3 py-2">{h.tran_date}</td>
                                <td className="px-3 py-2">{h.tran_type}</td>
                                <td className="px-3 py-2">{h.amount}</td>
                                <td className="px-3 py-2">{h.document_number ?? '—'}</td>
                                <td className="px-3 py-2">{h.comments ?? '—'}</td>
                                <td className="px-3 py-2">
                                    {h.cancel_at
                                        ? <span className="text-zinc-500">{t('cancelled')}</span>
                                        : <button type="button" onClick={() => cancelRow(h.id)} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('cancel')}</button>}
                                </td>
                            </tr>
                        ))}
                    </ResponsiveTable>
                </Card>
            )}
        </AppLayout>
    );
}
