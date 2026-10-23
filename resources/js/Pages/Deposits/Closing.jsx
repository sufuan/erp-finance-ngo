import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {ActionButton, Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Deposit closing (Step 12) — payable = balance + profit − charge − penalty. */
export default function DepositClosing({ member, memberQuery, accounts, accountId, preview, closings, businessDate }) {
    const form = useForm({ account_id: accountId ?? '', closing_date: businessDate ?? '', reason: '', profit_payment: '' });
    const [searching, setSearching] = useState(false);
    const lookup = (e) => {
        e.preventDefault();
        router.get('/deposits/closing', { member: e.target.elements.member.value }, { preserveState: true, onStart: () => setSearching(true), onFinish: () => setSearching(false) });
    };
    const pickAccount = (id) => {
        form.setData('account_id', id);
        router.get('/deposits/closing', { member: memberQuery, account_id: id }, { preserveState: true, preserveScroll: true });
    };
    const submit = (e) => { e.preventDefault(); form.post('/deposits/closing', { preserveScroll: true }); };
    const cancelRow = (id, cb) => router.post(`/deposits/closing/${id}/cancel`, {}, { preserveScroll: true, ...cb });

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('deposit_closing')}</h1>
            <Card className="mb-4">
                <form onSubmit={lookup} className="flex flex-wrap items-end gap-3">
                    <div className="min-w-56 flex-1">
                        <Field label={t('member_no')}>
                            <input name="member" className={inputCls} defaultValue={memberQuery} />
                        </Field>
                    </div>
                    <PrimaryButton type="submit" loading={searching}>{t('search')}</PrimaryButton>
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

            {preview && (
                <Card className="mb-4">
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('current_balance')}>
                            <input className={inputCls} value={preview.balance} readOnly />
                        </Field>
                        <Field label={t('profit_payment')}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={form.data.profit_payment} onChange={(e) => form.setData('profit_payment', e.target.value)} />
                        </Field>
                        <Field label={t('closing_charge')}>
                            <input className={inputCls} value={preview.closing_charge} readOnly />
                        </Field>
                        <Field label={t('premature_penalty')}>
                            <input className={inputCls} value={preview.penalty} readOnly />
                        </Field>
                        <Field label={t('payable_amount')}>
                            <input className={inputCls} value={preview.payable} readOnly />
                        </Field>
                        <Field label={t('closing_date')} required error={form.errors.closing_date}>
                            <input type="date" className={inputCls} value={form.data.closing_date} onChange={(e) => form.setData('closing_date', e.target.value)} required />
                        </Field>
                        <Field label={t('closing_reason')} error={form.errors.reason}>
                            <input className={inputCls} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                        </Field>
                        <div className="flex items-end">
                            <PrimaryButton type="submit" loading={form.processing}>{t('deposit_closing')}</PrimaryButton>
                        </div>
                    </form>
                </Card>
            )}

            <Card>
                <ResponsiveTable head={[t('branch'), t('member_no'), t('account_number'), t('closing_date'), t('current_balance'), t('profit_payment'), t('closing_charge'), t('payable_amount'), t('closing_reason'), t('closed_by'), t('cancelled_by'), t('actions')]}>
                    {closings.map((c) => (
                        <tr key={c.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{c.branch_code ?? '—'}</td>
                            <td className="px-3 py-2">{c.member ?? '—'}</td>
                            <td className="px-3 py-2">{c.account_number}</td>
                            <td className="px-3 py-2">{c.closing_date}</td>
                            <td className="px-3 py-2">{c.balance}</td>
                            <td className="px-3 py-2">{c.profit_payment}</td>
                            <td className="px-3 py-2">{c.closing_charge}</td>
                            <td className="px-3 py-2">{c.payable_amount}</td>
                            <td className="px-3 py-2">{c.reason ?? '—'}</td>
                            <td className="px-3 py-2">{c.closed_by ?? '—'} · {c.closed_at ? String(c.closed_at).slice(0, 10) : ''}</td>
                            <td className="px-3 py-2">{c.cancelled_by ?? '—'}</td>
                            <td className="px-3 py-2">
                                {!c.cancelled_at && (
                                    <ActionButton fire={(cb) => cancelRow(c.id, cb)} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('cancel')}</ActionButton>
                                )}
                            </td>
                        </tr>
                    ))}
                    {closings.length === 0 && <tr><td className="px-3 py-3" colSpan={12}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
