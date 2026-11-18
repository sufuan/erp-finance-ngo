import { Link } from '@inertiajs/react';
import { Card, ResponsiveTable } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/**
 * শাখা সমূহের রেজিস্টার — the Director-only list of every branch's
 * registers (client round 3, Part C). Clicking a branch opens that
 * branch's daily register or weekly balance sheet with the branch
 * preset. Branch Managers never see this list — they open the two
 * registers directly for their own branch.
 */
export default function BranchRegisters({ branches = [] }) {
    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('branch_registers')}</h1>
            <Card>
                <ResponsiveTable head={[t('branch'), t('business_date'), t('daily_office_register'), t('weekly_balance_sheet')]}>
                    {branches.map((b) => (
                        <tr key={b.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2 font-medium">{b.code} — {b.name}</td>
                            <td className="px-3 py-2">{b.business_date}</td>
                            <td className="px-3 py-2">
                                <Link className="text-emerald-700 underline dark:text-emerald-400" href={`/reports/manager-register?branch_id=${b.id}`}>
                                    {t('daily_office_register')}
                                </Link>
                            </td>
                            <td className="px-3 py-2">
                                <Link className="text-emerald-700 underline dark:text-emerald-400" href={`/reports/weekly-balance?branch_id=${b.id}`}>
                                    {t('weekly_balance_sheet')}
                                </Link>
                            </td>
                        </tr>
                    ))}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
