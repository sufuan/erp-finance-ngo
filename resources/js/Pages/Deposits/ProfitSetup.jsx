import { router, useForm } from '@inertiajs/react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Account profit setup (Step 13). */
export default function ProfitSetup({ member, memberQuery, accounts, accountId, setups, businessDate }) {
    const form = useForm({ account_id: accountId ?? '', effective_date: businessDate ?? '', profit_rate: '' });
    const lookup = (e) => { e.preventDefault(); router.get('/deposits/profit/setup', { member: e.target.elements.member.value }, { preserveState: true }); };
    const submit = (e) => { e.preventDefault(); form.post('/deposits/profit/setup', { preserveScroll: true }); };
    const cancelRow = (id) => router.post(`/deposits/profit/setup/${id}/cancel`, {}, { preserveScroll: true });

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('account_profit_setup')}</h1>
            <Card className="mb-4">
                <form onSubmit={lookup} className="flex flex-wrap items-end gap-3">
                    <div className="min-w-56 flex-1">
                        <Field label={t('member_no')}>
                            <input name="member" className={inputCls} defaultValue={memberQuery} />
                        </Field>
                    </div>
                    <PrimaryButton type="submit">{t('search')}</PrimaryButton>
                </form>
                {member && (
                    <form onSubmit={submit} className="mt-3 grid gap-4 sm:grid-cols-3">
                        <Field label={t('account_number')} error={form.errors.account_id}>
                            <select className={inputCls} value={form.data.account_id} onChange={(e) => form.setData('account_id', e.target.value)} required>
                                <option value="">—</option>
                                {accounts.map((a) => <option key={a.id} value={a.id}>{a.account_number} · {a.product} · {a.current_balance}</option>)}
                            </select>
                        </Field>
                        <Field label={t('effective_date')} required error={form.errors.effective_date}>
                            <input type="date" className={inputCls} value={form.data.effective_date} onChange={(e) => form.setData('effective_date', e.target.value)} required />
                        </Field>
                        <Field label={t('profit_rate')} required error={form.errors.profit_rate}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={form.data.profit_rate} onChange={(e) => form.setData('profit_rate', e.target.value)} required />
                        </Field>
                        <div className="sm:col-span-3">
                            <PrimaryButton type="submit" disabled={form.processing}>{t('set_profit_rate')}</PrimaryButton>
                        </div>
                    </form>
                )}
            </Card>
            <Card>
                <ResponsiveTable head={[t('account_number'), t('member_no'), t('effective_date'), t('serial'), t('profit_rate'), t('set_by'), t('cancelled_by'), t('actions')]}>
                    {setups.map((s) => (
                        <tr key={s.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{s.account_number}</td>
                            <td className="px-3 py-2">{s.member ?? '—'}</td>
                            <td className="px-3 py-2">{s.effective_date}</td>
                            <td className="px-3 py-2">{s.serial}</td>
                            <td className="px-3 py-2">{s.profit_rate}</td>
                            <td className="px-3 py-2">{s.setup_by ?? '—'} · {s.setup_at ? String(s.setup_at).slice(0, 10) : ''}</td>
                            <td className="px-3 py-2">{s.cancelled_by ?? '—'}</td>
                            <td className="px-3 py-2">
                                {!s.cancelled_at && <button type="button" onClick={() => cancelRow(s.id)} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('cancel')}</button>}
                            </td>
                        </tr>
                    ))}
                    {setups.length === 0 && <tr><td className="px-3 py-3" colSpan={8}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
