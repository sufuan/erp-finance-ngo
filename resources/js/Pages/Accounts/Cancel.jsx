import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ActionButton, Card, Field, ResponsiveTable, TextInput } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

export default function Cancel({ voucher = null, q = '', searched = false }) {
    const [number, setNumber] = useState(q);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    const lookup = (e) => {
        e.preventDefault();
        router.get('/accounts/cancel', { q: number }, { preserveState: true });
    };
    const cancel = () => {
        setBusy(true);
        router.post(`/accounts/vouchers/${voucher.id}/cancel`, { reason }, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    return (
        <AppLayout>
            <div className="mx-auto max-w-4xl space-y-4 p-4">
                <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t('acc_transaction_cancel')}</h1>
                <Card className="p-4">
                    <form onSubmit={lookup} className="flex flex-wrap items-end gap-3">
                        <div className="min-w-56 flex-1">
                            <Field label={t('acc_voucher_no')} required>
                                <TextInput value={number} onChange={(e) => setNumber(e.target.value)} required />
                            </Field>
                        </div>
                        <button type="submit" className="rounded bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-700">{t('search')}</button>
                    </form>
                    <p className="mt-2 text-xs text-slate-500">{t('acc_cancel_hint')}</p>
                    {searched && !voucher && <p className="mt-2 text-sm text-rose-600">{t('acc_not_found')}</p>}
                </Card>
                {voucher && (
                    <Card className="space-y-3 p-4">
                        <dl className="grid gap-2 text-sm sm:grid-cols-4">
                            <div><dt className="text-slate-500">{t('acc_voucher_no')}</dt><dd className="font-medium">{voucher.batch_number}</dd></div>
                            <div><dt className="text-slate-500">{t('date')}</dt><dd className="font-medium">{voucher.transaction_date}</dd></div>
                            <div><dt className="text-slate-500">{t('acc_voucher_type')}</dt><dd className="font-medium">{voucher.voucher_type}</dd></div>
                            <div><dt className="text-slate-500">{t('amount')}</dt><dd className="font-medium">{Number(voucher.total_amount).toLocaleString()}</dd></div>
                            <div><dt className="text-slate-500">{t('status')}</dt><dd className="font-medium">{voucher.status}</dd></div>
                            <div><dt className="text-slate-500">{t('branch')}</dt><dd className="font-medium">{voucher.branch}</dd></div>
                            <div><dt className="text-slate-500">{t('posted_by')}</dt><dd className="font-medium">{voucher.entry_by ?? '—'}</dd></div>
                        </dl>
                        {(voucher.lines ?? []).length > 0 && (
                            <ResponsiveTable head={[t('ledger'), t('debit'), t('credit')]}>
                                {voucher.lines.map((l, i) => (
                                    <tr key={i} className="border-t border-slate-100 dark:border-slate-700">
                                        <td className="px-3 py-2">{l.ledger_code} — {l.ledger_name}</td>
                                        <td className="px-3 py-2 text-right">{Number(l.debit) > 0 ? Number(l.debit).toLocaleString() : '—'}</td>
                                        <td className="px-3 py-2 text-right">{Number(l.credit) > 0 ? Number(l.credit).toLocaleString() : '—'}</td>
                                    </tr>
                                ))}
                            </ResponsiveTable>
                        )}
                        {voucher.status !== 'Cancelled' && (
                            <>
                                <Field label={t('acc_cancel_reason')} required>
                                    <TextInput value={reason} onChange={(e) => setReason(e.target.value)} />
                                </Field>
                                <ActionButton fire={cancel} disabled={busy || !reason} className="bg-rose-600 text-white hover:bg-rose-700">
                                    {t('acc_cancel_voucher')}
                                </ActionButton>
                            </>
                        )}
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
