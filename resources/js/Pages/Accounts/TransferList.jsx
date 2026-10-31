import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ActionButton, Card, ResponsiveTable } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

export default function TransferList({ view = 'all', rows = [], accessibleBranchIds = [] }) {
    const [busy, setBusy] = useState(null);
    const act = (id, action, extra = {}) => {
        setBusy(id + action);
        router.post(`/accounts/transfers/${id}/${action}`, extra, { preserveScroll: true, onFinish: () => setBusy(null) });
    };
    const canActOn = (branchId) => accessibleBranchIds.includes(branchId);

    return (
        <AppLayout>
            <div className="mx-auto max-w-6xl space-y-4 p-4">
                <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t(view === 'receive' ? 'acc_product_receive_approval' : 'acc_transfer_request_list')}</h1>
                <div className="flex flex-wrap gap-2">
                    {[['all', 'acc_view_all'], ['outgoing', 'acc_outgoing'], ['incoming', 'acc_incoming'], ['receive', 'acc_receive_queue']].map(([v, label]) => (
                        <Link key={v} href={`/accounts/transfer-requests?view=${v}`}
                            className={`rounded px-3 py-1.5 text-sm font-medium ${view === v ? 'bg-cyan-600 text-white' : 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200'}`}>
                            {t(label)}
                        </Link>
                    ))}
                </div>
                <Card className="p-0">
                    <ResponsiveTable head={[t('acc_request_no'), t('date'), t('acc_transfer_kind'), t('acc_from_branch'), t('acc_to_branch'), t('amount'), t('acc_item_name'), t('status'), '']}>
                        {rows.map((r) => (
                            <tr key={r.id} className="border-t border-slate-100 dark:border-slate-700">
                                <td className="px-3 py-2 font-medium">{r.request_no}</td>
                                <td className="px-3 py-2">{r.date}</td>
                                <td className="px-3 py-2">{r.kind === 'Fund' ? t('acc_kind_fund') : t('acc_kind_product')}</td>
                                <td className="px-3 py-2">{r.from_branch}</td>
                                <td className="px-3 py-2">{r.to_branch}</td>
                                <td className="px-3 py-2 text-right">{Number(r.amount) > 0 ? Number(r.amount).toLocaleString() : '—'}</td>
                                <td className="px-3 py-2">{r.item_name ?? '—'}{r.quantity ? ` × ${Number(r.quantity)}` : ''}</td>
                                <td className="px-3 py-2">{r.status}</td>
                                <td className="px-3 py-2">
                                    <div className="flex justify-end gap-2">
                                        {r.status === 'Pending' && canActOn(r.from_branch_id) && (
                                            <ActionButton fire={() => act(r.id, 'approve')} disabled={busy === r.id + 'approve'} className="bg-emerald-600 text-white hover:bg-emerald-700">{t('acc_approve_send')}</ActionButton>
                                        )}
                                        {r.status === 'Approved' && canActOn(r.to_branch_id) && (
                                            <ActionButton fire={() => act(r.id, 'receive')} disabled={busy === r.id + 'receive'} className="bg-cyan-600 text-white hover:bg-cyan-700">{t('acc_receive')}</ActionButton>
                                        )}
                                        {r.status === 'Pending' && canActOn(r.from_branch_id) && (
                                            <ActionButton fire={() => act(r.id, 'close', { to: 'Rejected' })} disabled={busy === r.id + 'close'} className="bg-rose-600 text-white hover:bg-rose-700">{t('acc_reject')}</ActionButton>
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {rows.length === 0 && (
                            <tr><td colSpan={9} className="px-3 py-6 text-center text-slate-500">{t('no_data')}</td></tr>
                        )}
                    </ResponsiveTable>
                </Card>
            </div>
        </AppLayout>
    );
}
