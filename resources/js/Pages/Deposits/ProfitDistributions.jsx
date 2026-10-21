import { Link } from '@inertiajs/react';
import { Card, ResponsiveTable } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Profit distribution list (Step 13). */
export default function ProfitDistributions({ distributions }) {
    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('profit_distribution_list')}</h1>
            <Card>
                <ResponsiveTable head={[t('sl'), t('samity'), t('account_type'), t('from_date'), t('upto_date'), t('profit_basis'), t('profit_rate'), t('distribution_amount'), t('status'), t('actions')]}>
                    {distributions.map((d, i) => (
                        <tr key={d.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{i + 1}</td>
                            <td className="px-3 py-2">{d.samity ?? '—'}</td>
                            <td className="px-3 py-2">{d.account_type}</td>
                            <td className="px-3 py-2">{d.from_date}</td>
                            <td className="px-3 py-2">{d.to_date}</td>
                            <td className="px-3 py-2">{d.profit_basis}</td>
                            <td className="px-3 py-2">{d.profit_rate}</td>
                            <td className="px-3 py-2">{d.distribution_amount}</td>
                            <td className="px-3 py-2">{d.status}</td>
                            <td className="px-3 py-2">
                                <Link href={`/deposits/profit/distributions/${d.id}`} className="rounded-lg px-2 py-1 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/20">{t('details')}</Link>
                            </td>
                        </tr>
                    ))}
                    {distributions.length === 0 && <tr><td className="px-3 py-3" colSpan={10}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
