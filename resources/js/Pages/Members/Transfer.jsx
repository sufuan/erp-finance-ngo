import { useForm } from '@inertiajs/react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Member transfer (Step 8) — balances carry; blocked by active loans. */
export default function MemberTransfer({ members, samities, businessDate, transfers }) {
    const { data, setData, post, processing, errors } = useForm({
        member_id: '', to_samity_id: '', transfer_date: businessDate ?? '', reason: '', manager_approved: false,
    });
    const submit = (e) => { e.preventDefault(); post('/members/transfer', { preserveScroll: true }); };
    const selected = members.find((m) => String(m.id) === String(data.member_id));

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('member_transfer')}</h1>
            <Card className="mb-6">
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('members')} required error={errors.member_id}>
                        <select className={inputCls} value={data.member_id} onChange={(e) => setData('member_id', e.target.value)} required>
                            <option value="">—</option>
                            {members.filter((m) => m.status === 'Active').map((m) => <option key={m.id} value={m.id}>{m.label}</option>)}
                        </select>
                    </Field>
                    <Field label={t('current_value')}>
                        <input className={inputCls} value={selected ? (samities.find((s) => s.id === selected.samity_id)?.name ?? '—') : ''} readOnly />
                    </Field>
                    <Field label={t('to_samity')} required error={errors.to_samity_id}>
                        <select className={inputCls} value={data.to_samity_id} onChange={(e) => setData('to_samity_id', e.target.value)} required>
                            <option value="">—</option>
                            {samities.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('transfer_date')} required error={errors.transfer_date}>
                        <input type="date" className={inputCls} value={data.transfer_date} onChange={(e) => setData('transfer_date', e.target.value)} required />
                    </Field>
                    <Field label={t('transfer_reason')} error={errors.reason}>
                        <input className={inputCls} value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                    </Field>
                    <label className="flex min-h-[44px] items-center gap-2 text-sm">
                        <input type="checkbox" className="h-5 w-5" checked={!!data.manager_approved} onChange={(e) => setData('manager_approved', e.target.checked)} />
                        {t('manager_approved')}
                    </label>
                    {errors.manager_approved && <span className="text-sm text-red-600 dark:text-red-400">{errors.manager_approved}</span>}
                    <div className="sm:col-span-2">
                        <PrimaryButton type="submit" loading={processing}>{t('transfer')}</PrimaryButton>
                    </div>
                </form>
            </Card>

            <Card>
                <h2 className="mb-3 font-medium">{t('history')}</h2>
                <ResponsiveTable head={[t('member_no'), t('from_samity'), t('to_samity'), t('transfer_date'), t('transfer_reason')]}>
                    {transfers.map((tr) => (
                        <tr key={tr.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{tr.member ? `${tr.member.member_no} — ${tr.member.name}` : '—'}</td>
                            <td className="px-3 py-2">{samities.find((s) => s.id === tr.from_samity_id)?.name ?? tr.from_samity_id ?? '—'}</td>
                            <td className="px-3 py-2">{samities.find((s) => s.id === tr.to_samity_id)?.name ?? tr.to_samity_id ?? '—'}</td>
                            <td className="px-3 py-2">{String(tr.transfer_date).slice(0, 10)}</td>
                            <td className="px-3 py-2">{tr.reason ?? '—'}</td>
                        </tr>
                    ))}
                    {transfers.length === 0 && <tr><td className="px-3 py-3" colSpan={5}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
