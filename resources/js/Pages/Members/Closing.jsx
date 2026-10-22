import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ButtonSpinner, Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Member closing (Step 8) — blocked by active loans / savings balances. */
export default function MemberClosing({ members, reasons, businessDate, closings }) {
    const { data, setData, post, processing, errors } = useForm({
        member_id: '', closing_date: businessDate ?? '', closing_reason: 'Voluntary', share_disposition: 'Refunded',
    });
    const submit = (e) => { e.preventDefault(); post('/members/closing', { preserveScroll: true }); };
    const [searching, setSearching] = useState(false);
    const search = () => router.get('/members/closing', {}, {
        preserveState: true,
        onStart: () => setSearching(true), onFinish: () => setSearching(false),
    });

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('member_closing')}</h1>
            <Card className="mb-6">
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('members')} required error={errors.member_id}>
                        <select className={inputCls} value={data.member_id} onChange={(e) => setData('member_id', e.target.value)} required>
                            <option value="">—</option>
                            {members.filter((m) => m.status !== 'Closed').map((m) => <option key={m.id} value={m.id}>{m.label}</option>)}
                        </select>
                    </Field>
                    <Field label={t('closing_date')} required error={errors.closing_date}>
                        <input type="date" className={inputCls} value={data.closing_date} onChange={(e) => setData('closing_date', e.target.value)} required />
                    </Field>
                    <Field label={t('closing_reason')} required error={errors.closing_reason}>
                        <select className={inputCls} value={data.closing_reason} onChange={(e) => setData('closing_reason', e.target.value)}>
                            {reasons.map((r) => <option key={r} value={r}>{t(`reason_${r.toLowerCase()}`)}</option>)}
                        </select>
                    </Field>
                    <Field label={t('share_disposition')} required error={errors.share_disposition}>
                        <select className={inputCls} value={data.share_disposition} onChange={(e) => setData('share_disposition', e.target.value)}>
                            <option value="Refunded">{t('share_refunded')}</option>
                            <option value="Forfeited">{t('share_forfeited')}</option>
                        </select>
                    </Field>
                    <div className="flex gap-2 sm:col-span-2">
                        <PrimaryButton type="submit" loading={processing}>{t('submit')}</PrimaryButton>
                        <button type="button" onClick={search} disabled={searching} className="inline-flex min-h-[44px] items-center gap-2 rounded-lg border border-zinc-300 px-4 py-2 text-sm disabled:opacity-50 dark:border-zinc-700">{searching && <ButtonSpinner />}{t('search')}</button>
                    </div>
                </form>
            </Card>

            <Card>
                <ResponsiveTable head={[t('member_no'), t('closing_date'), t('closing_reason'), t('share_disposition'), t('closed_by'), t('cancelled_by'), t('actions')]}>
                    {closings.map((c) => (
                        <tr key={c.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{c.member ? `${c.member.member_no} — ${c.member.name}` : '—'}</td>
                            <td className="px-3 py-2">{String(c.closing_date).slice(0, 10)}</td>
                            <td className="px-3 py-2">{c.closing_reason}</td>
                            <td className="px-3 py-2">{c.share_disposition === 'Refunded' ? t('share_refunded') : t('share_forfeited')} ({c.share_amount_at_closing})</td>
                            <td className="px-3 py-2">{c.closed_by_name ?? '—'} · {c.closed_at ? String(c.closed_at).replace('T', ' ').slice(0, 10) : ''}</td>
                            <td className="px-3 py-2">{c.cancelled_by_name ?? '—'}</td>
                            <td className="px-3 py-2">
                                {!c.cancelled_at && (
                                    <button type="button" onClick={() => router.post(`/members/closing/${c.id}/cancel`, {}, { preserveScroll: true })} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('cancel')}</button>
                                )}
                            </td>
                        </tr>
                    ))}
                    {closings.length === 0 && <tr><td className="px-3 py-3" colSpan={7}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
