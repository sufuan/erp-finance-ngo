import { router, useForm } from '@inertiajs/react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Loan Waiver (Step 19) — principal / profit / charge forgiveness. */
export default function Waiver({ loan }) {
    const { data, setData, post, processing, errors } = useForm({
        loan_id: loan.id, waiver_date: loan.business_date,
        principal_waive: '0', profit_waive: '0', charge_waive: '0', comments: '',
    });
    const submit = (e) => { e.preventDefault(); post('/loans/waiver'); };
    const cancel = (id) => router.post(`/loans/waiver/${id}/cancel`, {}, { preserveScroll: true });

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('loan_waiver')} — {loan.loan_number}</h1>
            <div className="grid gap-4 lg:grid-cols-3">
                <Card>
                    <dl className="space-y-2 text-sm">
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('borrower')}</dt><dd className="text-right font-medium">{loan.borrower}</dd></div>
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('status')}</dt><dd>{loan.status}</dd></div>
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('outstanding_principal')}</dt><dd>{loan.outstanding.principal}</dd></div>
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('outstanding_profit')}</dt><dd>{loan.outstanding.profit}</dd></div>
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('outstanding_penalty')}</dt><dd>{loan.penalty_due}</dd></div>
                    </dl>
                </Card>
                <Card className="lg:col-span-2">
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('waiver_date')} error={errors.waiver_date}>
                            <input type="date" className={inputCls} value={data.waiver_date} onChange={(e) => setData('waiver_date', e.target.value)} />
                        </Field>
                        <Field label={t('principal_waive')} error={errors.principal_waive}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={data.principal_waive} onChange={(e) => setData('principal_waive', e.target.value)} />
                        </Field>
                        <Field label={t('profit_waive')} error={errors.profit_waive}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={data.profit_waive} onChange={(e) => setData('profit_waive', e.target.value)} />
                        </Field>
                        <Field label={t('charge_waive')} error={errors.charge_waive}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={data.charge_waive} onChange={(e) => setData('charge_waive', e.target.value)} />
                        </Field>
                        <Field label={t('comments')}>
                            <input className={inputCls} value={data.comments} onChange={(e) => setData('comments', e.target.value)} />
                        </Field>
                        <div className="flex items-end">
                            <PrimaryButton type="submit" loading={processing}>{t('waive')}</PrimaryButton>
                        </div>
                    </form>
                </Card>
            </div>
            <Card className="mt-4">
                <h2 className="mb-3 font-medium">{t('history')}</h2>
                <ResponsiveTable head={[t('waiver_date'), t('principal_waive'), t('profit_waive'), t('charge_waive'), t('total_waiver'), t('status'), t('actions')]}>
                    {loan.waivers.map((w) => (
                        <tr key={w.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{w.waiver_date}</td>
                            <td className="px-3 py-2">{w.principal_waive}</td>
                            <td className="px-3 py-2">{w.profit_waive}</td>
                            <td className="px-3 py-2">{w.charge_waive}</td>
                            <td className="px-3 py-2">{w.total_waiver}</td>
                            <td className="px-3 py-2">{w.cancelled ? t('cancel') : '—'}</td>
                            <td className="px-3 py-2">
                                {!w.cancelled && <button type="button" onClick={() => cancel(w.id)} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('cancel')}</button>}
                            </td>
                        </tr>
                    ))}
                    {loan.waivers.length === 0 && <tr><td className="px-3 py-3" colSpan={7}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
