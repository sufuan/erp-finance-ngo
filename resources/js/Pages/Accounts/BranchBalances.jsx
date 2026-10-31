import { Card, ResponsiveTable } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

export default function BranchBalances({ rows = [] }) {
    const totals = rows.reduce((acc, r) => ({
        clearing: acc.clearing + Number(r.clearing), cash: acc.cash + Number(r.cash), bank: acc.bank + Number(r.bank),
    }), { clearing: 0, cash: 0, bank: 0 });

    return (
        <AppLayout>
            <div className="mx-auto max-w-5xl space-y-4 p-4">
                <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t('acc_branch_wise_balance')}</h1>
                <Card className="p-0">
                    <ResponsiveTable head={[t('branch'), t('acc_clearing_balance'), t('cash'), t('bank')]}>
                        {rows.map((r, i) => (
                            <tr key={i} className="border-t border-slate-100 dark:border-slate-700">
                                <td className="px-3 py-2 font-medium">{r.branch}</td>
                                <td className="px-3 py-2 text-right">{Number(r.clearing).toLocaleString()}</td>
                                <td className="px-3 py-2 text-right">{Number(r.cash).toLocaleString()}</td>
                                <td className="px-3 py-2 text-right">{Number(r.bank).toLocaleString()}</td>
                            </tr>
                        ))}
                        <tr className="border-t-2 border-slate-200 bg-slate-50 font-semibold dark:border-slate-600 dark:bg-slate-800">
                            <td className="px-3 py-2">{t('total')}</td>
                            <td className="px-3 py-2 text-right">{totals.clearing.toLocaleString()}</td>
                            <td className="px-3 py-2 text-right">{totals.cash.toLocaleString()}</td>
                            <td className="px-3 py-2 text-right">{totals.bank.toLocaleString()}</td>
                        </tr>
                    </ResponsiveTable>
                </Card>
                <p className="text-xs text-slate-500">{t('acc_clearing_net_zero_hint')}</p>
            </div>
        </AppLayout>
    );
}
