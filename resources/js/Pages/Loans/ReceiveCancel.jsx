import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Installment receive cancel (Step 18) — only the latest receipt can be cancelled. */
export default function ReceiveCancel({ mode, member, memberQuery, loans, loanId, receives }) {
    const [cancelling, setCancelling] = useState(null);
    const form = useForm({ reason: '' });
    const lookup = (e) => { e.preventDefault(); router.get('/loans/receive/cancel', { mode, member: e.target.elements.member.value }, { preserveState: true }); };
    const pickLoan = (id) => router.get('/loans/receive/cancel', { mode, member: memberQuery, loan_id: id }, { preserveState: true, preserveScroll: true });
    const switchMode = (m) => router.get('/loans/receive/cancel', { mode: m }, { preserveState: false });
    const submit = (e) => { e.preventDefault(); form.post(`/loans/receive/${cancelling}/cancel`, { preserveScroll: true, onSuccess: () => setCancelling(null) }); };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t(mode === 'staff' ? 'staff_loan_installment_cancel' : 'installment_receive_cancel')}</h1>
            <div className="mb-4 flex gap-2">
                {['member', 'staff'].map((m) => (
                    <button key={m} type="button" onClick={() => switchMode(m)} className={`min-h-[44px] rounded-lg px-3 py-2 text-sm ${mode === m ? 'bg-emerald-600 text-white' : 'border border-zinc-300 dark:border-zinc-700'}`}>
                        {t(m === 'staff' ? 'staff_loan_installment_cancel' : 'installment_receive_cancel')}
                    </button>
                ))}
            </div>
            {mode !== 'staff' && (
                <Card className="mb-4">
                    <form onSubmit={lookup} className="flex flex-wrap items-end gap-3">
                        <div className="min-w-56 flex-1">
                            <Field label={t('member_no')} required>
                                <input name="member" className={inputCls} defaultValue={memberQuery} />
                            </Field>
                        </div>
                        <PrimaryButton type="submit">{t('search')}</PrimaryButton>
                    </form>
                    {member && (
                        <div className="mt-3 max-w-md">
                            <Field label={t('member_account')}>
                                <select className={inputCls} value={loanId ?? ''} onChange={(e) => pickLoan(e.target.value)}>
                                    <option value="">—</option>
                                    {loans.map((l) => <option key={l.id} value={l.id}>{l.loan_number} · {l.status}</option>)}
                                </select>
                            </Field>
                        </div>
                    )}
                </Card>
            )}
            {mode === 'staff' && (
                <Card className="mb-4">
                    <Field label={t('member_account')}>
                        <select className={inputCls} value={loanId ?? ''} onChange={(e) => pickLoan(e.target.value)}>
                            <option value="">—</option>
                            {loans.map((l) => <option key={l.id} value={l.id}>{l.loan_number} · {l.status}</option>)}
                        </select>
                    </Field>
                </Card>
            )}

            {cancelling !== null && (
                <Card className="mb-4">
                    <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                        <div className="min-w-56 flex-1">
                            <Field label={t('cancel_reason')} required error={form.errors.reason}>
                                <input className={inputCls} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                            </Field>
                        </div>
                        <PrimaryButton type="submit" disabled={form.processing}>{t('cancel')}</PrimaryButton>
                    </form>
                </Card>
            )}

            <Card>
                <ResponsiveTable head={[t('date'), t('installment_amount'), t('investment_amount'), t('profit_amount'), t('penalty_charge'), t('cancelled_by'), t('actions')]}>
                    {receives.map((r) => (
                        <tr key={r.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{r.receive_date}</td>
                            <td className="px-3 py-2">{r.installment_amount}</td>
                            <td className="px-3 py-2">{r.investment_amount}</td>
                            <td className="px-3 py-2">{r.profit_amount}</td>
                            <td className="px-3 py-2">{r.penalty_charge}</td>
                            <td className="px-3 py-2">{r.cancel_at ?? '—'}</td>
                            <td className="px-3 py-2">
                                {r.is_latest && (
                                    <button type="button" onClick={() => setCancelling(r.id)} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('cancel')}</button>
                                )}
                            </td>
                        </tr>
                    ))}
                    {receives.length === 0 && <tr><td className="px-3 py-3" colSpan={7}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
