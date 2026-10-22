import { useForm } from '@inertiajs/react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Write Off + recovery collection (Step 19). */
export default function WriteOff({ loan }) {
    const wo = useForm({ loan_id: loan.id, writeoff_date: loan.business_date, comments: '' });
    const rec = useForm({
        loan_id: loan.id, recovery_date: loan.business_date,
        principal_recovery: '0', profit_recovery: '0', charge_recovery: '0', comments: '',
    });

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('write_off')} — {loan.loan_number}</h1>
            <div className="grid gap-4 lg:grid-cols-3">
                <Card>
                    <dl className="space-y-2 text-sm">
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('borrower')}</dt><dd className="text-right font-medium">{loan.borrower}</dd></div>
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('status')}</dt><dd>{loan.status}</dd></div>
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('outstanding_principal')}</dt><dd>{loan.outstanding.principal}</dd></div>
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('outstanding_profit')}</dt><dd>{loan.outstanding.profit}</dd></div>
                        <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('outstanding_penalty')}</dt><dd>{loan.penalty_due}</dd></div>
                        {loan.writeoff && <div className="flex justify-between gap-2"><dt className="text-zinc-500">{t('writeoff_date')}</dt><dd>{loan.writeoff.writeoff_date} — {loan.writeoff.total_amount}</dd></div>}
                    </dl>
                </Card>
                <Card>
                    <h2 className="mb-3 font-medium">{t('write_off')}</h2>
                    <form onSubmit={(e) => { e.preventDefault(); wo.post('/loans/writeoff'); }} className="grid gap-4">
                        <Field label={t('writeoff_date')} error={wo.errors.writeoff_date}>
                            <input type="date" className={inputCls} value={wo.data.writeoff_date} onChange={(e) => wo.setData('writeoff_date', e.target.value)} />
                        </Field>
                        <Field label={t('comments')}>
                            <input className={inputCls} value={wo.data.comments} onChange={(e) => wo.setData('comments', e.target.value)} />
                        </Field>
                        <PrimaryButton type="submit" loading={wo.processing} disabled={loan.status === 'WrittenOff'}>{t('write_off')}</PrimaryButton>
                    </form>
                </Card>
                <Card>
                    <h2 className="mb-3 font-medium">{t('collect_recovery')}</h2>
                    <form onSubmit={(e) => { e.preventDefault(); rec.post('/loans/writeoff/collect'); }} className="grid gap-4">
                        <Field label={t('recovery_date')} error={rec.errors.recovery_date}>
                            <input type="date" className={inputCls} value={rec.data.recovery_date} onChange={(e) => rec.setData('recovery_date', e.target.value)} />
                        </Field>
                        <Field label={t('principal_recovery')} error={rec.errors.principal_recovery}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={rec.data.principal_recovery} onChange={(e) => rec.setData('principal_recovery', e.target.value)} />
                        </Field>
                        <Field label={t('profit_recovery')} error={rec.errors.profit_recovery}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={rec.data.profit_recovery} onChange={(e) => rec.setData('profit_recovery', e.target.value)} />
                        </Field>
                        <Field label={t('charge_recovery')} error={rec.errors.charge_recovery}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={rec.data.charge_recovery} onChange={(e) => rec.setData('charge_recovery', e.target.value)} />
                        </Field>
                        <PrimaryButton type="submit" loading={rec.processing} disabled={loan.status !== 'WrittenOff'}>{t('collect')}</PrimaryButton>
                    </form>
                </Card>
            </div>
            <Card className="mt-4">
                <h2 className="mb-3 font-medium">{t('history')}</h2>
                <ResponsiveTable head={[t('recovery_date'), t('principal_recovery'), t('profit_recovery'), t('charge_recovery'), t('total_recovery')]}>
                    {loan.collections.map((c) => (
                        <tr key={c.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{c.recovery_date}</td>
                            <td className="px-3 py-2">{c.principal_recovery}</td>
                            <td className="px-3 py-2">{c.profit_recovery}</td>
                            <td className="px-3 py-2">{c.charge_recovery}</td>
                            <td className="px-3 py-2">{c.total_recovery}</td>
                        </tr>
                    ))}
                    {loan.collections.length === 0 && <tr><td className="px-3 py-3" colSpan={5}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
