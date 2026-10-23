import { router } from '@inertiajs/react';
import { useState } from 'react';
import {ActionButton, Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Loan disbursement list (Step 18) — disbursement posts the voucher. */
export default function DisbursementList({ loans, samities, filters }) {
    const [searching, setSearching] = useState(false);
    const search = (e) => { e.preventDefault(); router.get('/loans/disbursement', Object.fromEntries(new FormData(e.target).entries()), { preserveState: true, onStart: () => setSearching(true), onFinish: () => setSearching(false) }); };
    const disburse = (id, cb) => router.post(`/loans/disbursement/${id}`, {}, { preserveScroll: true, ...cb });

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('loan_disbursement_list')}</h1>
            <Card className="mb-4">
                <form onSubmit={search} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Field label={t('samity')}>
                        <select name="samity_id" className={inputCls} defaultValue={filters.samity_id ?? ''}>
                            <option value="">{t('all')}</option>
                            {samities.map((s) => <option key={s.id} value={s.id}>{s.samity_code} — {s.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('member_no')}>
                        <input name="member" className={inputCls} defaultValue={filters.member ?? ''} />
                    </Field>
                    <Field label={t('from_date')}>
                        <input type="date" name="from_date" className={inputCls} defaultValue={filters.from_date ?? ''} />
                    </Field>
                    <Field label={t('upto_date')}>
                        <input type="date" name="to_date" className={inputCls} defaultValue={filters.to_date ?? ''} />
                    </Field>
                    <div className="flex items-end">
                        <PrimaryButton type="submit" loading={searching}>{t('search')}</PrimaryButton>
                    </div>
                </form>
            </Card>
            <Card>
                <ResponsiveTable head={[t('samity'), t('member_no'), t('loan_amount'), t('total_repayment'), t('profit_rate'), t('installment_from_date'), t('no_of_installments'), t('actions')]}>
                    {loans.map((l) => (
                        <tr key={l.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{l.samity ?? '—'}</td>
                            <td className="px-3 py-2">{l.member ?? '—'} ({l.loan_number})</td>
                            <td className="px-3 py-2">{l.loan_amount}</td>
                            <td className="px-3 py-2">{l.total_repayment}</td>
                            <td className="px-3 py-2">{l.profit_rate}</td>
                            <td className="px-3 py-2">{l.installment_from_date}</td>
                            <td className="px-3 py-2">{l.no_of_installments}</td>
                            <td className="px-3 py-2">
                                <ActionButton fire={(cb) => disburse(l.id, cb)} className="rounded-lg px-2 py-1 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/20">{t('disburse')}</ActionButton>
                            </td>
                        </tr>
                    ))}
                    {loans.length === 0 && <tr><td className="px-3 py-3" colSpan={8}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
