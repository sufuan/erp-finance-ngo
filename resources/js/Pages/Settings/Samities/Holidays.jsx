import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../../Components/ui';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

/** Holiday management (Step 7): single date or a range (max 31 days). */
export default function Holidays({ holidays, options, samities, activeBranchId, businessDate }) {
    const [mode, setMode] = useState('single');
    const { data, setData, post, processing, errors } = useForm({
        branch_id: activeBranchId ?? '', samity_id: '', details: '',
        holiday_date: businessDate ?? '', date_from: '', date_to: '',
    });
    const submit = (e) => {
        e.preventDefault();
        post('/settings/holidays', { preserveScroll: true });
    };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('holidays')}</h1>
            <p className="mb-4 text-sm text-zinc-500 dark:text-zinc-400">{t('holiday_help')}</p>

            <Card className="mb-6">
                <div className="mb-4 flex gap-2">
                    {['single', 'range'].map((m) => (
                        <button key={m} type="button" onClick={() => setMode(m)}
                            className={`min-h-[44px] rounded-lg px-3 py-2 text-sm ${mode === m ? 'bg-emerald-600 text-white' : 'border border-zinc-300 dark:border-zinc-700'}`}>
                            {t(m === 'single' ? 'single_date' : 'date_range')}
                        </button>
                    ))}
                </div>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label={`${t('branch')} *`} error={errors.branch_id}>
                        <select className={inputCls} value={data.branch_id} onChange={(e) => setData('branch_id', e.target.value)} required>
                            <option value="">—</option>
                            {options.branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('samity')} error={errors.samity_id}>
                        <select className={inputCls} value={data.samity_id} onChange={(e) => setData('samity_id', e.target.value)}>
                            <option value="">—</option>
                            {samities.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </select>
                    </Field>
                    <Field label={`${t('holiday_details')} *`} error={errors.details}>
                        <input className={inputCls} value={data.details} onChange={(e) => setData('details', e.target.value)} required />
                    </Field>
                    {mode === 'single' ? (
                        <Field label={`${t('holiday_date')} *`} error={errors.holiday_date}>
                            <input type="date" className={inputCls} value={data.holiday_date} onChange={(e) => setData('holiday_date', e.target.value)} required />
                        </Field>
                    ) : (
                        <>
                            <Field label={`${t('date_from')} *`} error={errors.date_from}>
                                <input type="date" className={inputCls} value={data.date_from} onChange={(e) => setData('date_from', e.target.value)} required />
                            </Field>
                            <Field label={`${t('date_to')} *`} error={errors.date_to}>
                                <input type="date" className={inputCls} value={data.date_to} onChange={(e) => setData('date_to', e.target.value)} required />
                            </Field>
                        </>
                    )}
                    <div className="sm:col-span-2 lg:col-span-3">
                        <PrimaryButton type="submit" disabled={processing}>{t('save')}</PrimaryButton>
                    </div>
                </form>
            </Card>

            <Card>
                <ResponsiveTable head={[t('holiday_date'), t('branch'), t('samity'), t('holiday_details'), t('actions')]}>
                    {holidays.map((h) => (
                        <tr key={h.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{String(h.holiday_date).slice(0, 10)}</td>
                            <td className="px-3 py-2">{h.branch?.name ?? '—'}</td>
                            <td className="px-3 py-2">{h.samity?.name ?? '—'}</td>
                            <td className="px-3 py-2">{h.details}</td>
                            <td className="px-3 py-2">
                                <button type="button" onClick={() => router.delete(`/settings/holidays/${h.id}`, { preserveScroll: true })} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('delete')}</button>
                            </td>
                        </tr>
                    ))}
                    {holidays.length === 0 && <tr><td className="px-3 py-3" colSpan={5}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
