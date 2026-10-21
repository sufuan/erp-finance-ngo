import { router, useForm } from '@inertiajs/react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Update deposit installment + transaction profile setup (Step 13). */
export default function TransactionProfile({ member, memberQuery, accounts, accountId, profiles }) {
    const form = useForm({ account_id: accountId ?? '', installment_amount: '', from_date: '', to_date: '', comments: '' });
    const lookup = (e) => { e.preventDefault(); router.get('/deposits/profit/profile', { member: e.target.elements.member.value }, { preserveState: true }); };
    const submit = (e) => { e.preventDefault(); form.post('/deposits/profit/profile', { preserveScroll: true }); };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('transaction_profile_setup')}</h1>
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
                    <form onSubmit={submit} className="mt-3 grid gap-4 sm:grid-cols-2">
                        <Field label={t('deposit_account')} error={form.errors.account_id}>
                            <select className={inputCls} value={form.data.account_id} onChange={(e) => form.setData('account_id', e.target.value)} required>
                                <option value="">—</option>
                                {accounts.map((a) => <option key={a.id} value={a.id}>{a.account_number} · {a.product} · {a.installment_amount ?? '—'}</option>)}
                            </select>
                        </Field>
                        <Field label={`${t('installment_amount')} *`} error={form.errors.installment_amount}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={form.data.installment_amount} onChange={(e) => form.setData('installment_amount', e.target.value)} required />
                        </Field>
                        <Field label={t('from_date')} error={form.errors.from_date}>
                            <input type="date" className={inputCls} value={form.data.from_date} onChange={(e) => form.setData('from_date', e.target.value)} />
                        </Field>
                        <Field label={t('upto_date')} error={form.errors.to_date}>
                            <input type="date" className={inputCls} value={form.data.to_date} onChange={(e) => form.setData('to_date', e.target.value)} />
                        </Field>
                        <Field label={t('comments')}>
                            <input className={inputCls} value={form.data.comments} onChange={(e) => form.setData('comments', e.target.value)} />
                        </Field>
                        <div className="flex items-end">
                            <PrimaryButton type="submit" disabled={form.processing}>{t('update_installment')}</PrimaryButton>
                        </div>
                    </form>
                )}
            </Card>
            <Card>
                <ResponsiveTable head={[t('account_number'), t('member_no'), t('installment_amount'), t('from_date'), t('upto_date'), t('comments')]}>
                    {profiles.map((p) => (
                        <tr key={p.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{p.account_number}</td>
                            <td className="px-3 py-2">{p.member ?? '—'}</td>
                            <td className="px-3 py-2">{p.installment_amount}</td>
                            <td className="px-3 py-2">{p.from_date ?? '—'}</td>
                            <td className="px-3 py-2">{p.to_date ?? '—'}</td>
                            <td className="px-3 py-2">{p.comments ?? '—'}</td>
                        </tr>
                    ))}
                    {profiles.length === 0 && <tr><td className="px-3 py-3" colSpan={6}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
