import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Deposit profit payment (Step 13) — pays posted profit in cash. */
export default function ProfitPayment({ member, memberQuery, accounts, accountId, pending, paid, accountRate, accountBalance, businessDate }) {
    const form = useForm({ account_id: accountId ?? '', profit_amount: '', payment_date: businessDate ?? '', comments: '' });
    const [searching, setSearching] = useState(false);
    const lookup = (e) => { e.preventDefault(); router.get('/deposits/profit/payment', { member: e.target.elements.member.value }, { preserveState: true, onStart: () => setSearching(true), onFinish: () => setSearching(false) }); };
    const pick = (id) => { form.setData('account_id', id); router.get('/deposits/profit/payment', { member: memberQuery, account_id: id }, { preserveState: true, preserveScroll: true }); };
    const submit = (e) => { e.preventDefault(); form.post('/deposits/profit/payment', { preserveScroll: true }); };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('deposit_profit_payment')}</h1>
            <Card>
                <form onSubmit={lookup} className="flex flex-wrap items-end gap-3">
                    <div className="min-w-56 flex-1">
                        <Field label={t('member_no')}>
                            <input name="member" className={inputCls} defaultValue={memberQuery} />
                        </Field>
                    </div>
                    <PrimaryButton type="submit" loading={searching}>{t('search')}</PrimaryButton>
                </form>
                {member && (
                    <form onSubmit={submit} className="mt-3 grid gap-4 sm:grid-cols-2">
                        <Field label={t('deposit_account')} error={form.errors.account_id}>
                            <select className={inputCls} value={form.data.account_id} onChange={(e) => pick(e.target.value)} required>
                                <option value="">—</option>
                                {accounts.map((a) => <option key={a.id} value={a.id}>{a.account_number} · {a.product} · {a.current_balance}</option>)}
                            </select>
                        </Field>
                        <Field label={t('account_balance')}>
                            <input className={inputCls} value={accountBalance ?? ''} readOnly />
                        </Field>
                        <Field label={t('profit_rate')}>
                            <input className={inputCls} value={accountRate ?? ''} readOnly />
                        </Field>
                        <Field label={t('profit_paid')}>
                            <input className={inputCls} value={paid ?? ''} readOnly />
                        </Field>
                        <Field label={t('profit_due')}>
                            <input className={inputCls} value={pending ?? ''} readOnly />
                        </Field>
                        <Field label={t('profit_amount')} required error={form.errors.profit_amount}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={form.data.profit_amount} onChange={(e) => form.setData('profit_amount', e.target.value)} required />
                        </Field>
                        <Field label={t('payment_date')} required error={form.errors.payment_date}>
                            <input type="date" className={inputCls} value={form.data.payment_date} onChange={(e) => form.setData('payment_date', e.target.value)} required />
                        </Field>
                        <Field label={t('comments')}>
                            <input className={inputCls} value={form.data.comments} onChange={(e) => form.setData('comments', e.target.value)} />
                        </Field>
                        <div className="sm:col-span-2">
                            <PrimaryButton type="submit" loading={form.processing}>{t('profit_payment')}</PrimaryButton>
                        </div>
                    </form>
                )}
            </Card>
        </AppLayout>
    );
}
