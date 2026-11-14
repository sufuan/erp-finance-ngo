import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

const STATUS_TONES = {
    paid: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    partial: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    due: 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300',
};

/**
 * Collect Loan — the one-click loan collection screen: find a loan
 * (number / member no / member name, inside the user's branch and
 * ticked samities), see the amount currently due prefilled, click
 * Collect once. The post runs through the same CollectionService
 * batch path as the Collection Sheet; the redirect back here brings
 * the refreshed panel + installment table.
 */
export default function CollectLoan({ query = '', results = [], panel = null, canCollect = false }) {
    const { props } = usePage();
    const [searching, setSearching] = useState(false);
    const form = useForm({ loan_id: panel?.id ?? '', amount: panel?.due_amount ?? '' });

    // A fresh panel (first open, another loan, or the post-collect
    // redirect) re-prefills the amount with that loan's current due —
    // the typed value never survives into a different due figure.
    useEffect(() => {
        if (panel) form.setData({ loan_id: panel.id, amount: panel.due_amount });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [panel?.id, panel?.due_amount]);

    const search = (e) => {
        e.preventDefault();
        router.get('/collection/collect-loan', { loan: e.target.elements.loan.value }, {
            preserveState: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });
    };

    const collect = (e) => {
        e.preventDefault();
        form.post('/collection/collect-loan', { preserveScroll: true });
    };

    const stats = panel ? [
        [t('total_installments'), panel.summary.total],
        [t('installments_collected'), panel.summary.paid_count],
        [t('installments_remaining'), panel.summary.remaining_count],
        [t('collected_amount'), panel.summary.collected_amount],
        [t('remaining_amount'), panel.summary.remaining_amount],
    ] : [];

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('collect_loan')}</h1>
            <Card className="mb-4">
                <form onSubmit={search} className="flex flex-wrap items-end gap-3">
                    <div className="min-w-56 flex-1">
                        <Field label={t('loan_or_member_no')} required>
                            <input name="loan" className={inputCls} defaultValue={query} required />
                        </Field>
                    </div>
                    <PrimaryButton type="submit" loading={searching}>{t('search')}</PrimaryButton>
                </form>
            </Card>

            {!panel && results.length > 0 && (
                <Card className="mb-4">
                    <h2 className="mb-2 font-medium">{t('loan_list')}</h2>
                    <div className="divide-y divide-zinc-100 dark:divide-zinc-800">
                        {results.map((r) => (
                            <Link key={r.id} href={`/collection/collect-loan?loan_id=${r.id}`} className="flex flex-wrap items-center gap-x-4 gap-y-1 py-2 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <span className="font-medium">{r.loan_number}</span>
                                <span>{r.member_name} ({r.member_no})</span>
                                <span className="text-zinc-500">{r.samity}</span>
                                <span className="ms-auto">{t('outstanding')}: {r.outstanding.total}</span>
                            </Link>
                        ))}
                    </div>
                </Card>
            )}
            {!panel && query && results.length === 0 && (
                <Card className="mb-4"><p className="text-sm text-zinc-500">{t('no_data_found')}</p></Card>
            )}

            {panel && (
                <>
                    <Card className="mb-4">
                        <div className="grid gap-3 text-sm sm:grid-cols-3">
                            <p><span className="block text-xs text-zinc-500">{t('member_name')}</span><span className="font-medium">{panel.member_name}</span> ({panel.member_no})</p>
                            <p><span className="block text-xs text-zinc-500">{t('loan_number')}</span><span className="font-medium">{panel.loan_number}</span></p>
                            <p><span className="block text-xs text-zinc-500">{t('samity')}</span>{panel.samity}</p>
                            <p><span className="block text-xs text-zinc-500">{t('product')}</span>{panel.product}</p>
                            <p><span className="block text-xs text-zinc-500">{t('business_date')}</span>{panel.business_date}</p>
                            <p><span className="block text-xs text-zinc-500">{t('outstanding')}</span><span className="font-semibold">{panel.outstanding.total}</span>
                                <span className="block text-xs text-zinc-500">{t('outstanding_principal')}: {panel.outstanding.principal} · {t('outstanding_profit')}: {panel.outstanding.profit} · {t('penalty_charge')}: {panel.outstanding.penalty}</span>
                            </p>
                        </div>
                        {canCollect && (
                            <form onSubmit={collect} className="mt-4 flex flex-wrap items-end gap-3">
                                <div className="min-w-48 flex-1 sm:max-w-xs">
                                    <Field label={t('due_amount')} error={form.errors.amount} required>
                                        <input type="number" step="0.01" min="0" className={inputCls} value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} required />
                                    </Field>
                                </div>
                                <PrimaryButton type="submit" loading={form.processing}>{t('collect')}</PrimaryButton>
                            </form>
                        )}
                        {props.errors?.lines && <p className="mt-2 text-sm text-rose-600">{props.errors.lines}</p>}
                    </Card>

                    <Card className="mb-4">
                        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                            {stats.map(([label, value]) => (
                                <div key={label}>
                                    <dt className="text-xs text-zinc-500">{label}</dt>
                                    <dd className="text-lg font-semibold">{value}</dd>
                                </div>
                            ))}
                        </dl>
                    </Card>

                    <Card>
                        <ResponsiveTable head={['#', t('due_date'), t('amount'), t('paid'), t('status')]}>
                            {panel.installments.map((r) => (
                                <tr key={r.no} className="border-b border-zinc-100 dark:border-zinc-800">
                                    <td className="px-3 py-2">{r.no}</td>
                                    <td className="px-3 py-2">{r.due_date}</td>
                                    <td className="px-3 py-2">{r.amount}</td>
                                    <td className="px-3 py-2">{r.paid}</td>
                                    <td className="px-3 py-2"><span className={`rounded px-1.5 py-0.5 text-xs font-medium ${STATUS_TONES[r.status]}`}>{t(r.status)}</span></td>
                                </tr>
                            ))}
                            {panel.installments.length === 0 && <tr><td className="px-3 py-3" colSpan={5}>{t('no_data_found')}</td></tr>}
                        </ResponsiveTable>
                    </Card>
                </>
            )}
        </AppLayout>
    );
}
