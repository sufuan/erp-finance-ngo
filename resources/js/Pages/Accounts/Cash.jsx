import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ActionButton, Card, Field, PrimaryButton, ResponsiveTable, SelectInput, TextInput } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

export default function Cash({ users = [], pending = [], counts = [], currentUserId = null, businessDate = null, branch = null }) {
    const transferForm = useForm({ from_user_id: currentUserId ?? '', to_user_id: '', amount: '', comments: '' });
    const countForm = useForm({ user_id: currentUserId ?? '', counted_balance: '' });
    const [receiving, setReceiving] = useState({});
    const [busy, setBusy] = useState(null);

    const submitTransfer = (e) => {
        e.preventDefault();
        transferForm.post('/accounts/cash', { preserveScroll: true, onSuccess: () => transferForm.reset('amount', 'comments') });
    };
    const submitCount = (e) => {
        e.preventDefault();
        countForm.post('/accounts/cash/count', { preserveScroll: true, onSuccess: () => countForm.reset('counted_balance') });
    };
    const receive = (tr) => {
        setBusy(tr.id);
        router.post(`/accounts/cash/${tr.id}/receive`, { counted_amount: receiving[tr.id] ?? '' }, { preserveScroll: true, onFinish: () => setBusy(null) });
    };
    const cancel = (tr) => {
        setBusy(tr.id);
        router.post(`/accounts/cash/${tr.id}/cancel`, {}, { preserveScroll: true, onFinish: () => setBusy(null) });
    };
    const userOptions = users.map((u) => <option key={u.id} value={u.id}>{u.name} ({u.username})</option>);

    return (
        <AppLayout>
            <div className="mx-auto max-w-6xl space-y-4 p-4">
                <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t('acc_cash_management')}</h1>
                {branch && <p className="text-sm text-slate-500">{branch.code} — {branch.name} · {t('business_date')}: {businessDate}</p>}

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card className="p-4">
                        <h2 className="mb-3 font-semibold">{t('acc_cash_transfer_request')}</h2>
                        <form onSubmit={submitTransfer} className="space-y-3">
                            <Field label={t('acc_from_officer')} error={transferForm.errors.from_user_id} required>
                                <SelectInput value={transferForm.data.from_user_id} onChange={(e) => transferForm.setData('from_user_id', e.target.value)}>
                                    <option value="">{t('select')}</option>
                                    {userOptions}
                                </SelectInput>
                            </Field>
                            <Field label={t('acc_to_cashier')} error={transferForm.errors.to_user_id} required>
                                <SelectInput value={transferForm.data.to_user_id} onChange={(e) => transferForm.setData('to_user_id', e.target.value)}>
                                    <option value="">{t('select')}</option>
                                    {userOptions}
                                </SelectInput>
                            </Field>
                            <Field label={t('amount')} error={transferForm.errors.amount} required>
                                <TextInput type="number" step="0.01" min="0.01" value={transferForm.data.amount} onChange={(e) => transferForm.setData('amount', e.target.value)} required />
                            </Field>
                            <Field label={t('notes')} error={transferForm.errors.comments}>
                                <TextInput value={transferForm.data.comments} onChange={(e) => transferForm.setData('comments', e.target.value)} />
                            </Field>
                            <PrimaryButton type="submit" loading={transferForm.processing}>{t('acc_send_cash')}</PrimaryButton>
                        </form>
                    </Card>

                    <Card className="p-4">
                        <h2 className="mb-3 font-semibold">{t('acc_day_end_count')}</h2>
                        <form onSubmit={submitCount} className="space-y-3">
                            <Field label={t('cashier')} error={countForm.errors.user_id} required>
                                <SelectInput value={countForm.data.user_id} onChange={(e) => countForm.setData('user_id', e.target.value)}>
                                    <option value="">{t('select')}</option>
                                    {userOptions}
                                </SelectInput>
                            </Field>
                            <Field label={t('acc_counted_amount')} error={countForm.errors.counted_balance} required>
                                <TextInput type="number" step="0.01" min="0" value={countForm.data.counted_balance} onChange={(e) => countForm.setData('counted_balance', e.target.value)} required />
                            </Field>
                            <PrimaryButton type="submit" loading={countForm.processing}>{t('acc_record_count')}</PrimaryButton>
                        </form>
                        {counts.length > 0 && (
                            <div className="mt-4">
                                <ResponsiveTable head={[t('cashier'), t('acc_expected'), t('acc_counted'), t('acc_variance')]}>
                                    {counts.map((c, i) => (
                                        <tr key={i} className="border-t border-slate-100 dark:border-slate-700">
                                            <td className="px-3 py-2">{c.user}</td>
                                            <td className="px-3 py-2 text-right">{Number(c.expected).toLocaleString()}</td>
                                            <td className="px-3 py-2 text-right">{Number(c.counted).toLocaleString()}</td>
                                            <td className={`px-3 py-2 text-right font-medium ${Number(c.difference) !== 0 ? 'text-rose-600' : 'text-emerald-600'}`}>{Number(c.difference).toLocaleString()}</td>
                                        </tr>
                                    ))}
                                </ResponsiveTable>
                            </div>
                        )}
                    </Card>
                </div>

                <Card className="p-0">
                    <h2 className="p-4 pb-0 font-semibold">{t('acc_pending_handovers')}</h2>
                    <ResponsiveTable head={[t('date'), t('acc_from_officer'), t('acc_to_cashier'), t('amount'), t('notes'), '']}>
                        {pending.map((tr) => (
                            <tr key={tr.id} className="border-t border-slate-100 dark:border-slate-700">
                                <td className="px-3 py-2">{tr.date}</td>
                                <td className="px-3 py-2">{tr.from_user}</td>
                                <td className="px-3 py-2">{tr.to_user}</td>
                                <td className="px-3 py-2 text-right">{Number(tr.amount).toLocaleString()}</td>
                                <td className="px-3 py-2">{tr.comments ?? ''}</td>
                                <td className="px-3 py-2">
                                    <div className="flex flex-wrap items-center justify-end gap-2">
                                        {(tr.to_user_id === currentUserId) && (
                                            <>
                                                <input type="number" step="0.01" min="0" className="w-28 rounded border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-600 dark:bg-slate-800"
                                                    placeholder={t('acc_counted_amount')} value={receiving[tr.id] ?? ''}
                                                    onChange={(e) => setReceiving({ ...receiving, [tr.id]: e.target.value })} />
                                                <ActionButton fire={() => receive(tr)} disabled={busy === tr.id || !(receiving[tr.id] ?? '')} className="bg-emerald-600 text-white hover:bg-emerald-700">{t('acc_receive')}</ActionButton>
                                            </>
                                        )}
                                        <ActionButton fire={() => cancel(tr)} disabled={busy === tr.id} className="bg-rose-600 text-white hover:bg-rose-700">{t('cancel')}</ActionButton>
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {pending.length === 0 && (
                            <tr><td colSpan={6} className="px-3 py-6 text-center text-slate-500">{t('no_data')}</td></tr>
                        )}
                    </ResponsiveTable>
                </Card>
            </div>
        </AppLayout>
    );
}
