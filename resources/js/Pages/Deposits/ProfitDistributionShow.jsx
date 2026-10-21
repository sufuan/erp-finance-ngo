import { router } from '@inertiajs/react';
import { Card, PrimaryButton, ResponsiveTable } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Distribution details + post-to-account (Step 13). */
export default function ProfitDistributionShow({ distribution, lines }) {
    const post = () => router.post(`/deposits/profit/distributions/${distribution.id}/post`, {}, { preserveScroll: true });

    return (
        <AppLayout>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3 print:hidden">
                <h1 className="text-xl font-semibold">{t('profit_details')} #{distribution.id}</h1>
                <div className="flex gap-2">
                    <button type="button" onClick={() => window.print()} className="min-h-[44px] rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700">{t('print')}</button>
                    {distribution.status !== 'Posted' && <PrimaryButton type="button" onClick={post}>{t('post_to_account')}</PrimaryButton>}
                </div>
            </div>
            <Card className="mb-4">
                <div className="grid gap-2 text-sm sm:grid-cols-3">
                    <span>{t('samity')}: {distribution.samity}</span>
                    <span>{t('account_type')}: {distribution.account_type}</span>
                    <span>{t('profit_basis')}: {distribution.profit_basis} — {t(`basis_${distribution.profit_basis.toLowerCase()}`)}</span>
                    <span>{t('from_date')}: {distribution.from_date}</span>
                    <span>{t('upto_date')}: {distribution.to_date}</span>
                    <span>{t('profit_rate')}: {distribution.profit_rate}</span>
                    <span>{t('distribution_amount')}: {distribution.distribution_amount}</span>
                    <span>{t('posting_date')}: {distribution.posting_date}</span>
                    <span>{t('status')}: {distribution.status}</span>
                </div>
            </Card>
            <Card>
                <ResponsiveTable head={[t('sl'), t('member_no'), t('samity'), t('account_number'), t('current_balance'), t('profit_on_amount'), t('profit_pending'), t('total_payable')]}>
                    {lines.map((l, i) => (
                        <tr key={l.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{i + 1}</td>
                            <td className="px-3 py-2">{l.member ?? '—'}</td>
                            <td className="px-3 py-2">{l.center}</td>
                            <td className="px-3 py-2">{l.account_number}</td>
                            <td className="px-3 py-2">{l.balance}</td>
                            <td className="px-3 py-2">{l.profit_on_amount}</td>
                            <td className="px-3 py-2">{l.profit_pending}</td>
                            <td className="px-3 py-2">{l.total_payable}</td>
                        </tr>
                    ))}
                    {lines.length === 0 && <tr><td className="px-3 py-3" colSpan={8}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
