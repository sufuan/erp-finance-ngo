import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ActionButton, Card, ResponsiveTable } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

export default function VoucherShow({ voucher, canAuthorize = false, canCancel = false, canBreakglass = false }) {
    const [reason, setReason] = useState('');
    const [breakGlass, setBreakGlass] = useState(false);
    const [busy, setBusy] = useState(false);
    const lines = voucher.lines ?? [];
    const movements = voucher.movements ?? [];

    const act = (url, extra = {}) => {
        setBusy(true);
        router.post(url, extra, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    return (
        <AppLayout>
            <div className="mx-auto max-w-4xl space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{voucher.batch_number}</h1>
                    <span className="rounded bg-slate-100 px-3 py-1 text-sm font-medium text-slate-700 dark:bg-slate-700 dark:text-slate-200">{voucher.status}</span>
                </div>
                <Card className="p-4">
                    <dl className="grid gap-2 text-sm sm:grid-cols-3">
                        <div><dt className="text-slate-500">{t('date')}</dt><dd className="font-medium">{voucher.transaction_date}</dd></div>
                        <div><dt className="text-slate-500">{t('business_date')}</dt><dd className="font-medium">{voucher.business_date}</dd></div>
                        <div><dt className="text-slate-500">{t('branch')}</dt><dd className="font-medium">{voucher.branch}</dd></div>
                        <div><dt className="text-slate-500">{t('acc_voucher_type')}</dt><dd className="font-medium">{voucher.voucher_type}</dd></div>
                        <div><dt className="text-slate-500">{t('amount')}</dt><dd className="font-medium">{Number(voucher.total_amount).toLocaleString()}</dd></div>
                        <div><dt className="text-slate-500">{t('posted_by')}</dt><dd className="font-medium">{voucher.entry_by ?? '—'}</dd></div>
                    </dl>
                    {voucher.comments && <p className="mt-3 text-sm text-slate-600 dark:text-slate-300">{voucher.comments}</p>}
                </Card>
                <Card className="p-0">
                    <ResponsiveTable head={[t('ledger'), t('debit'), t('credit'), t('narration')]}>
                        {lines.map((l, i) => (
                            <tr key={i} className="border-t border-slate-100 dark:border-slate-700">
                                <td className="px-3 py-2">{l.ledger_code} — {l.ledger_name}</td>
                                <td className="px-3 py-2 text-right">{Number(l.debit) > 0 ? Number(l.debit).toLocaleString() : '—'}</td>
                                <td className="px-3 py-2 text-right">{Number(l.credit) > 0 ? Number(l.credit).toLocaleString() : '—'}</td>
                                <td className="px-3 py-2">{l.comments ?? l.document_number ?? ''}</td>
                            </tr>
                        ))}
                    </ResponsiveTable>
                </Card>
                {movements.length > 0 && (
                    <Card className="p-0">
                        <ResponsiveTable head={[t('account'), t('tran_code'), t('amount'), t('balance'), t('status')]}>
                            {movements.map((m, i) => (
                                <tr key={i} className="border-t border-slate-100 dark:border-slate-700">
                                    <td className="px-3 py-2">{m.account_type} #{m.account_id}</td>
                                    <td className="px-3 py-2">{m.tran_code}</td>
                                    <td className="px-3 py-2 text-right">{Number(m.amount).toLocaleString()}</td>
                                    <td className="px-3 py-2 text-right">{Number(m.balance_after).toLocaleString()}</td>
                                    <td className="px-3 py-2">{m.cancelled ? t('acc_status_cancelled') : t('active')}</td>
                                </tr>
                            ))}
                        </ResponsiveTable>
                    </Card>
                )}
                {(canAuthorize || canCancel) && voucher.status !== 'Cancelled' && (
                    <Card className="space-y-3 p-4">
                        {canAuthorize && voucher.status === 'Pending' && (
                            <div className="flex flex-wrap items-center gap-3">
                                <ActionButton fire={() => act(`/accounts/vouchers/${voucher.id}/authorize`, breakGlass ? { break_glass: true, reason } : {})}
                                    disabled={busy || (breakGlass && !reason)} className="bg-emerald-600 text-white hover:bg-emerald-700">
                                    {t('acc_authorize')}
                                </ActionButton>
                                {canBreakglass && (
                                    <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                        <input type="checkbox" checked={breakGlass} onChange={(e) => setBreakGlass(e.target.checked)} />
                                        {t('acc_break_glass')}
                                    </label>
                                )}
                                {breakGlass && (
                                    <input className="w-full rounded border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800"
                                        placeholder={t('acc_break_glass_reason')} value={reason} onChange={(e) => setReason(e.target.value)} />
                                )}
                            </div>
                        )}
                        {canCancel && (
                            <div className="flex flex-wrap items-center gap-3">
                                <input className="min-w-52 flex-1 rounded border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800"
                                    placeholder={t('acc_cancel_reason')} value={reason} onChange={(e) => setReason(e.target.value)} />
                                <ActionButton fire={() => act(`/accounts/vouchers/${voucher.id}/cancel`, { reason })}
                                    className="bg-rose-600 text-white hover:bg-rose-700" disabled={busy || !reason}>
                                    {t('acc_cancel_voucher')}
                                </ActionButton>
                            </div>
                        )}
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
