import { router, useForm } from '@inertiajs/react';
import { Card, Field, PrimaryButton, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Loan installment receive (Step 18) — auto / manual / staff modes. */
export default function InstallmentReceive({ mode, member, memberQuery, loans, loanId, snapshot, depositAccounts }) {
    const form = useForm({
        loan_account_id: loanId ?? '', amount: '', receive_date: '', document_number: '',
        comments: '', penalty_charge: '', transfer_from_deposit: false, deposit_account_id: '',
        manual_investment: '', manual_profit: '', manual_penalty: '',
    });
    const lookup = (e) => { e.preventDefault(); router.get('/loans/receive', { mode, member: e.target.elements.member.value }, { preserveState: true }); };
    const pickLoan = (id) => router.get('/loans/receive', { mode, member: memberQuery, loan_id: id }, { preserveState: true, preserveScroll: true });
    const switchMode = (m) => router.get('/loans/receive', { mode: m }, { preserveState: false });
    const submit = (e) => { e.preventDefault(); form.post('/loans/receive', { preserveScroll: true }); };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t(mode === 'manual' ? 'manual_installment_receive' : mode === 'staff' ? 'staff_loan_installment_receive' : 'loan_installment_receive')}</h1>
            <div className="mb-4 flex flex-wrap gap-2">
                {['auto', 'manual', 'staff'].map((m) => (
                    <button key={m} type="button" onClick={() => switchMode(m)} className={`min-h-[44px] rounded-lg px-3 py-2 text-sm ${mode === m ? 'bg-emerald-600 text-white' : 'border border-zinc-300 dark:border-zinc-700'}`}>
                        {t(m === 'auto' ? 'loan_installment_receive' : m === 'manual' ? 'manual_installment_receive' : 'staff_loan_installment_receive')}
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
                </Card>
            )}

            {(member || mode === 'staff') && (
                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                            <Field label={t('member_account')} required error={form.errors.loan_account_id}>
                                <select className={inputCls} value={form.data.loan_account_id} onChange={(e) => { form.setData('loan_account_id', e.target.value); pickLoan(e.target.value); }} required>
                                    <option value="">—</option>
                                    {loans.map((l) => <option key={l.id} value={l.id}>{l.label ?? l.loan_number}</option>)}
                                </select>
                            </Field>
                            <Field label={t('installment_amount')} required error={form.errors.amount}>
                                <input type="number" step="0.01" min="0" className={inputCls} value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} required />
                            </Field>
                            {mode === 'manual' && (
                                <>
                                    <Field label={t('investment_amount')} error={form.errors.manual_investment}>
                                        <input type="number" step="0.01" min="0" className={inputCls} value={form.data.manual_investment} onChange={(e) => form.setData('manual_investment', e.target.value)} />
                                    </Field>
                                    <Field label={t('profit_amount')} error={form.errors.manual_profit}>
                                        <input type="number" step="0.01" min="0" className={inputCls} value={form.data.manual_profit} onChange={(e) => form.setData('manual_profit', e.target.value)} />
                                    </Field>
                                    <Field label={t('penalty_charge')} error={form.errors.manual_penalty}>
                                        <input type="number" step="0.01" min="0" className={inputCls} value={form.data.manual_penalty} onChange={(e) => form.setData('manual_penalty', e.target.value)} />
                                    </Field>
                                </>
                            )}
                            {mode !== 'manual' && (
                                <Field label={t('penalty_charge')} error={form.errors.penalty_charge}>
                                    <input type="number" step="0.01" min="0" className={inputCls} value={form.data.penalty_charge} onChange={(e) => form.setData('penalty_charge', e.target.value)} placeholder={snapshot?.penalty_due ?? ''} />
                                </Field>
                            )}
                            <Field label={t('installment_date')} required>
                                <input type="date" className={inputCls} value={form.data.receive_date} onChange={(e) => form.setData('receive_date', e.target.value)} />
                            </Field>
                            <Field label={t('document_number')}>
                                <input className={inputCls} value={form.data.document_number} onChange={(e) => form.setData('document_number', e.target.value)} />
                            </Field>
                            <Field label={t('comments')}>
                                <input className={inputCls} value={form.data.comments} onChange={(e) => form.setData('comments', e.target.value)} />
                            </Field>
                            <label className="flex min-h-[44px] items-center gap-2 text-sm">
                                <input type="checkbox" className="h-5 w-5" checked={!!form.data.transfer_from_deposit} onChange={(e) => form.setData('transfer_from_deposit', e.target.checked)} />
                                {t('transfer_from_deposit')}
                            </label>
                            {form.data.transfer_from_deposit && (
                                <Field label={t('deposit_account')} error={form.errors.deposit_account_id}>
                                    <select className={inputCls} value={form.data.deposit_account_id} onChange={(e) => form.setData('deposit_account_id', e.target.value)}>
                                        <option value="">—</option>
                                        {depositAccounts.map((a) => <option key={a.id} value={a.id}>{a.account_number} · {a.product} · {a.current_balance}</option>)}
                                    </select>
                                </Field>
                            )}
                            <div className="sm:col-span-2">
                                <PrimaryButton type="submit" disabled={form.processing}>{t('receive_installment')}</PrimaryButton>
                            </div>
                        </form>
                    </Card>
                    {snapshot && (
                        <Card>
                            <h2 className="mb-3 text-sm font-semibold tracking-wide text-zinc-500">{t('account_snapshot')}</h2>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('todays_due')}</dt><dd className="font-medium">{snapshot.todays_due}</dd></div>
                                <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('penalty_charge')}</dt><dd className="font-medium">{snapshot.penalty_due}</dd></div>
                                <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('loan_amount')}</dt><dd className="font-medium">{snapshot.loan_amount}</dd></div>
                                <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('total_installment')}</dt><dd className="font-medium">{snapshot.total_installment}</dd></div>
                                <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('paid_installment')}</dt><dd className="font-medium">{snapshot.paid_installment}</dd></div>
                                <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('due_installment')}</dt><dd className="font-medium">{snapshot.due_installment}</dd></div>
                                <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('installment_expire_date')}</dt><dd className="font-medium">{snapshot.expire_date}</dd></div>
                                <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('status')}</dt><dd className="font-medium">{snapshot.status}</dd></div>
                            </dl>
                        </Card>
                    )}
                </div>
            )}
        </AppLayout>
    );
}
