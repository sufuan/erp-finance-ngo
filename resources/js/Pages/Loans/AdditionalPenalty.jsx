import { router, useForm } from '@inertiajs/react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Additional EMI Penalty (Step 19). */
export default function AdditionalPenalty({ loan }) {
    const { data, setData, post, processing, errors } = useForm({
        loan_id: loan.id, entry_date: loan.business_date,
        additional_profit: '0', penalty_amount: '0', comments: '',
    });
    const submit = (e) => { e.preventDefault(); post('/loans/penalty'); };
    const cancel = (id) => router.post(`/loans/penalty/${id}/cancel`, {}, { preserveScroll: true });

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('additional_emi_penalty')} — {loan.loan_number}</h1>
            <div className="grid gap-4 lg:grid-cols-3">
                <Card>
                    <dl className="space-y-2 text-sm">
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('borrower')}</dt><dd className="text-right font-medium">{loan.borrower}</dd></div>
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('status')}</dt><dd>{loan.status}</dd></div>
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('outstanding_penalty')}</dt><dd>{loan.penalty_due}</dd></div>
                    </dl>
                </Card>
                <Card className="lg:col-span-2">
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('entry_date')} error={errors.entry_date}>
                            <input type="date" className={inputCls} value={data.entry_date} onChange={(e) => setData('entry_date', e.target.value)} />
                        </Field>
                        <Field label={t('penalty_amount')} error={errors.penalty_amount}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={data.penalty_amount} onChange={(e) => setData('penalty_amount', e.target.value)} />
                        </Field>
                        <Field label={t('additional_profit')} error={errors.additional_profit}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={data.additional_profit} onChange={(e) => setData('additional_profit', e.target.value)} />
                        </Field>
                        <Field label={t('comments')}>
                            <input className={inputCls} value={data.comments} onChange={(e) => setData('comments', e.target.value)} />
                        </Field>
                        <div className="flex items-end">
                            <PrimaryButton type="submit" loading={processing}>{t('submit')}</PrimaryButton>
                        </div>
                    </form>
                </Card>
            </div>
            <Card className="mt-4">
                <h2 className="mb-3 font-medium">{t('history')}</h2>
                <ResponsiveTable head={[t('entry_date'), t('penalty_amount'), t('additional_profit'), t('status'), t('actions')]}>
                    {loan.penalties.map((p) => (
                        <tr key={p.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{p.entry_date}</td>
                            <td className="px-3 py-2">{p.penalty_amount}</td>
                            <td className="px-3 py-2">{p.additional_profit}</td>
                            <td className="px-3 py-2">{p.cancelled ? t('cancel') : '—'}</td>
                            <td className="px-3 py-2">
                                {!p.cancelled && <button type="button" onClick={() => cancel(p.id)} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('cancel')}</button>}
                            </td>
                        </tr>
                    ))}
                    {loan.penalties.length === 0 && <tr><td className="px-3 py-3" colSpan={5}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
