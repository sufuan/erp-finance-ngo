import { useForm } from '@inertiajs/react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Loan Reschedule (Step 19) — current details + new schedule + fees. */
export default function Reschedule({ loan, freq_options, fee_types }) {
    const { data, setData, post, processing, errors } = useForm({
        loan_id: loan.id, reschedule_date: loan.business_date, additional_profit: '0',
        frequency: loan.frequency, no_of_installments: '', first_installment_date: loan.business_date,
        fees: Object.fromEntries(fee_types.map((f) => [f, ''])),
    });
    const submit = (e) => { e.preventDefault(); post('/loans/reschedule'); };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('loan_reschedule')} — {loan.loan_number}</h1>
            <div className="grid gap-4 lg:grid-cols-3">
                <Card>
                    <dl className="space-y-2 text-sm">
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('borrower')}</dt><dd className="text-right font-medium">{loan.borrower}</dd></div>
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('status')}</dt><dd>{loan.status}</dd></div>
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('loan_amount')}</dt><dd>{loan.loan_amount}</dd></div>
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('outstanding_principal')}</dt><dd>{loan.outstanding.principal}</dd></div>
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('outstanding_profit')}</dt><dd>{loan.outstanding.profit}</dd></div>
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('outstanding_penalty')}</dt><dd>{loan.penalty_due}</dd></div>
                    </dl>
                </Card>
                <Card className="lg:col-span-2">
                    <h2 className="mb-3 font-medium">{t('new_schedule_details')}</h2>
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('reschedule_date')} error={errors.reschedule_date}>
                            <input type="date" className={inputCls} value={data.reschedule_date} onChange={(e) => setData('reschedule_date', e.target.value)} />
                        </Field>
                        <Field label={t('additional_profit')} error={errors.additional_profit}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={data.additional_profit} onChange={(e) => setData('additional_profit', e.target.value)} />
                        </Field>
                        <Field label={t('frequency')} error={errors.frequency}>
                            <select className={inputCls} value={data.frequency} onChange={(e) => setData('frequency', e.target.value)}>
                                {freq_options.map((f) => <option key={f} value={f}>{f}</option>)}
                            </select>
                        </Field>
                        <Field label={t('no_of_installments')} error={errors.no_of_installments}>
                            <input type="number" min="1" className={inputCls} value={data.no_of_installments} onChange={(e) => setData('no_of_installments', e.target.value)} />
                        </Field>
                        <Field label={t('first_installment_date')} error={errors.first_installment_date}>
                            <input type="date" className={inputCls} value={data.first_installment_date} onChange={(e) => setData('first_installment_date', e.target.value)} />
                        </Field>
                        {fee_types.map((f) => (
                            <Field key={f} label={t(`fee_${f}`) || f}>
                                <input type="number" step="0.01" min="0" className={inputCls} value={data.fees[f]} onChange={(e) => setData('fees', { ...data.fees, [f]: e.target.value })} />
                            </Field>
                        ))}
                        <div className="flex items-end">
                            <PrimaryButton type="submit" disabled={processing}>{t('reschedule')}</PrimaryButton>
                        </div>
                    </form>
                </Card>
            </div>
            <Card className="mt-4">
                <h2 className="mb-3 font-medium">{t('current_schedule')}</h2>
                <ResponsiveTable head={[t('sl'), t('due_date'), t('installment_amount'), t('paid'), t('status')]}>
                    {loan.schedules.map((s, i) => (
                        <tr key={s.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{s.installment_no}</td>
                            <td className="px-3 py-2">{s.due_date}</td>
                            <td className="px-3 py-2">{s.total_due}</td>
                            <td className="px-3 py-2">{(Number(s.paid_principal) + Number(s.paid_profit)).toFixed(2)}</td>
                            <td className="px-3 py-2">{s.superseded ? t('superseded') : s.status}</td>
                        </tr>
                    ))}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
