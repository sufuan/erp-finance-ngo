import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {ActionButton, Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Insurance Claims (Step 19) — file, approve/reject, pay. */
export default function InsuranceClaims({ claims, members, loans, filters }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        member_id: '', loan_account_id: '', claim_type: 'death', claim_date: '', amount: '', documents: [],
    });
    const [searching, setSearching] = useState(false);
    const submit = (e) => { e.preventDefault(); post('/loans/insurance-claims', { onSuccess: () => reset() }); };
    const search = (e) => { e.preventDefault(); router.get('/loans/insurance-claims', Object.fromEntries(new FormData(e.target).entries()), { preserveState: true, onStart: () => setSearching(true), onFinish: () => setSearching(false) }); };
    const decide = (id, decision, cb) => router.post(`/loans/insurance-claims/${id}/decide`, { decision }, { preserveScroll: true, ...cb });
    const pay = (id, cb) => router.post(`/loans/insurance-claims/${id}/pay`, {}, { preserveScroll: true, ...cb });

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('insurance_claims')}</h1>
            <div className="grid gap-4 lg:grid-cols-3">
                <Card>
                    <h2 className="mb-3 font-medium">{t('file_claim')}</h2>
                    <form onSubmit={submit} className="grid gap-4">
                        <Field label={t('member_name')} error={errors.member_id}>
                            <select className={inputCls} value={data.member_id} onChange={(e) => setData('member_id', e.target.value)}>
                                <option value="">—</option>
                                {members.map((m) => <option key={m.id} value={m.id}>{m.label}</option>)}
                            </select>
                        </Field>
                        <Field label={t('loan_number')}>
                            <select className={inputCls} value={data.loan_account_id} onChange={(e) => setData('loan_account_id', e.target.value)}>
                                <option value="">—</option>
                                {loans.map((l) => <option key={l.id} value={l.id}>{l.label}</option>)}
                            </select>
                        </Field>
                        <Field label={t('claim_type')} error={errors.claim_type}>
                            <select className={inputCls} value={data.claim_type} onChange={(e) => setData('claim_type', e.target.value)}>
                                <option value="death">{t('death')}</option>
                                <option value="disability">{t('disability')}</option>
                            </select>
                        </Field>
                        <Field label={t('claim_date')} error={errors.claim_date}>
                            <input type="date" className={inputCls} value={data.claim_date} onChange={(e) => setData('claim_date', e.target.value)} />
                        </Field>
                        <Field label={t('claim_amount')} error={errors.amount}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                        </Field>
                        <PrimaryButton type="submit" loading={processing}>{t('file_claim')}</PrimaryButton>
                    </form>
                </Card>
                <Card className="lg:col-span-2">
                    <form onSubmit={search} className="mb-4 grid gap-4 sm:grid-cols-3">
                        <Field label={t('status')}>
                            <select name="status" className={inputCls} defaultValue={filters.status ?? ''}>
                                <option value="">{t('all')}</option>
                                {['Filed', 'Approved', 'Rejected', 'Paid'].map((s) => <option key={s} value={s}>{s}</option>)}
                            </select>
                        </Field>
                        <div className="flex items-end">
                            <PrimaryButton type="submit" loading={searching}>{t('search')}</PrimaryButton>
                        </div>
                    </form>
                    <ResponsiveTable head={[t('member_name'), t('loan_number'), t('claim_type'), t('claim_date'), t('claim_amount'), t('status'), t('actions')]}>
                        {claims.data.map((c) => (
                            <tr key={c.id} className="border-b border-zinc-100 dark:border-zinc-800">
                                <td className="px-3 py-2">{c.member ?? '—'}</td>
                                <td className="px-3 py-2">{c.loan_number ?? '—'}</td>
                                <td className="px-3 py-2">{t(c.claim_type)}</td>
                                <td className="px-3 py-2">{c.claim_date}</td>
                                <td className="px-3 py-2">{c.amount}</td>
                                <td className="px-3 py-2">{c.status}</td>
                                <td className="px-3 py-2">
                                    <div className="flex flex-wrap gap-1">
                                        {c.status === 'Filed' && <>
                                            <ActionButton fire={(cb) => decide(c.id, 'approve', cb)} className="rounded-lg px-2 py-1 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/20">{t('approve')}</ActionButton>
                                            <ActionButton fire={(cb) => decide(c.id, 'reject', cb)} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('reject')}</ActionButton>
                                        </>}
                                        {c.status === 'Approved' && <ActionButton fire={(cb) => pay(c.id, cb)} className="rounded-lg px-2 py-1 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/20">{t('pay')}</ActionButton>}
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {claims.data.length === 0 && <tr><td className="px-3 py-3" colSpan={7}>{t('no_data_found')}</td></tr>}
                    </ResponsiveTable>
                </Card>
            </div>
        </AppLayout>
    );
}
