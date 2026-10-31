import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ActionButton, Card, ResponsiveTable } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

export default function Authorization({ mode = 'queue', vouchers = [], canAuthorize = false, canCancel = false, canBreakglass = false, currentUserId = null }) {
    const [reasons, setReasons] = useState({});
    const [glass, setGlass] = useState({});
    const [busy, setBusy] = useState(null);
    const title = mode === 'approval' ? 'acc_financial_transaction_approval' : 'acc_authorization_list';

    const authorize = (v) => {
        setBusy(v.id);
        const extra = glass[v.id] ? { break_glass: true, reason: reasons[v.id] ?? '' } : {};
        router.post(`/accounts/vouchers/${v.id}/authorize`, extra, { preserveScroll: true, onFinish: () => setBusy(null) });
    };
    const cancel = (v) => {
        setBusy(v.id);
        router.post(`/accounts/vouchers/${v.id}/cancel`, { reason: reasons[v.id] ?? '' }, { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    return (
        <AppLayout>
            <div className="mx-auto max-w-6xl space-y-4 p-4">
                <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t(title)}</h1>
                <Card className="p-0">
                    <ResponsiveTable head={[t('acc_voucher_no'), t('date'), t('branch'), t('acc_voucher_type'), t('amount'), t('posted_by'), '']}>
                        {vouchers.map((v) => (
                            <tr key={v.id} className="border-t border-slate-100 dark:border-slate-700">
                                <td className="px-3 py-2 font-medium">{v.batch_number}</td>
                                <td className="px-3 py-2">{v.transaction_date}</td>
                                <td className="px-3 py-2">{v.branch}</td>
                                <td className="px-3 py-2">{v.voucher_type}</td>
                                <td className="px-3 py-2 text-right">{Number(v.total_amount).toLocaleString()}</td>
                                <td className="px-3 py-2">{v.entry_by}</td>
                                <td className="px-3 py-2">
                                    <div className="flex flex-wrap items-center justify-end gap-2">
                                        {canAuthorize && (
                                            <ActionButton fire={() => authorize(v)} disabled={busy === v.id || (glass[v.id] && !(reasons[v.id] ?? ''))} className="bg-emerald-600 text-white hover:bg-emerald-700">
                                                {t('acc_authorize')}
                                            </ActionButton>
                                        )}
                                        {canBreakglass && (
                                            <label className="flex items-center gap-1 text-xs text-slate-600 dark:text-slate-300">
                                                <input type="checkbox" checked={!!glass[v.id]} onChange={(e) => setGlass({ ...glass, [v.id]: e.target.checked })} />
                                                {t('acc_break_glass_short')}
                                            </label>
                                        )}
                                        {canCancel && (
                                            <>
                                                <input className="w-36 rounded border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-600 dark:bg-slate-800"
                                                    placeholder={t('acc_cancel_reason')} value={reasons[v.id] ?? ''}
                                                    onChange={(e) => setReasons({ ...reasons, [v.id]: e.target.value })} />
                                                <ActionButton fire={() => cancel(v)} disabled={busy === v.id || !(reasons[v.id] ?? '')} className="bg-rose-600 text-white hover:bg-rose-700">
                                                    {t('cancel')}
                                                </ActionButton>
                                            </>
                                        )}
                                        {!canCancel && (canAuthorize || canBreakglass) && (
                                            <input className="w-36 rounded border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-600 dark:bg-slate-800"
                                                placeholder={t('acc_break_glass_reason')} value={reasons[v.id] ?? ''}
                                                onChange={(e) => setReasons({ ...reasons, [v.id]: e.target.value })} />
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {vouchers.length === 0 && (
                            <tr><td colSpan={7} className="px-3 py-6 text-center text-slate-500">{t('no_data')}</td></tr>
                        )}
                    </ResponsiveTable>
                </Card>
            </div>
        </AppLayout>
    );
}
