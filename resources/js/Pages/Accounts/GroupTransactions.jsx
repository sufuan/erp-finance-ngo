import { Link } from '@inertiajs/react';
import { Card, ResponsiveTable } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

export default function GroupTransactions({ rows = [] }) {
    return (
        <AppLayout>
            <div className="mx-auto max-w-5xl space-y-4 p-4">
                <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t('acc_group_transaction_list')}</h1>
                <Card className="p-0">
                    <ResponsiveTable head={[t('date'), t('acc_ledger_group_select'), t('amount'), t('acc_voucher_no'), t('status'), t('posted_by')]}>
                        {rows.map((row) => (
                            <tr key={row.id} className="border-t border-slate-100 dark:border-slate-700">
                                <td className="px-3 py-2">{row.date}</td>
                                <td className="px-3 py-2 font-medium">{row.group}</td>
                                <td className="px-3 py-2 text-right">{Number(row.amount).toLocaleString()}</td>
                                <td className="px-3 py-2">
                                    {row.voucher_id
                                        ? <Link href={`/accounts/vouchers/${row.voucher_id}`} className="font-medium text-cyan-700 hover:underline dark:text-cyan-300">{row.voucher_no}</Link>
                                        : '—'}
                                </td>
                                <td className="px-3 py-2">{row.status ?? '—'}</td>
                                <td className="px-3 py-2">{row.entry_by ?? ''}</td>
                            </tr>
                        ))}
                        {rows.length === 0 && (
                            <tr><td colSpan={6} className="px-3 py-6 text-center text-slate-500">{t('no_data')}</td></tr>
                        )}
                    </ResponsiveTable>
                </Card>
            </div>
        </AppLayout>
    );
}
