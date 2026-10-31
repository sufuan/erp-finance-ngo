import { Card, ResponsiveTable } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

export default function CashierBalances({ rows = [] }) {
    const total = rows.reduce((acc, r) => acc + Number(r.balance), 0);
    return (
        <AppLayout>
            <div className="mx-auto max-w-4xl space-y-4 p-4">
                <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t('acc_cashier_balance_report')}</h1>
                <Card className="p-0">
                    <ResponsiveTable head={[t('cashier'), t('username'), t('balance')]}>
                        {rows.map((r, i) => (
                            <tr key={i} className="border-t border-slate-100 dark:border-slate-700">
                                <td className="px-3 py-2 font-medium">{r.user}</td>
                                <td className="px-3 py-2">{r.username}</td>
                                <td className="px-3 py-2 text-right">{Number(r.balance).toLocaleString()}</td>
                            </tr>
                        ))}
                        <tr className="border-t-2 border-slate-200 bg-slate-50 font-semibold dark:border-slate-600 dark:bg-slate-800">
                            <td className="px-3 py-2">{t('total')}</td>
                            <td className="px-3 py-2" />
                            <td className="px-3 py-2 text-right">{total.toLocaleString()}</td>
                        </tr>
                        {rows.length === 0 && (
                            <tr><td colSpan={3} className="px-3 py-6 text-center text-slate-500">{t('no_data')}</td></tr>
                        )}
                    </ResponsiveTable>
                </Card>
            </div>
        </AppLayout>
    );
}
