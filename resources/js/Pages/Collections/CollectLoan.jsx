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
export default function CollectLoan({ query = '', results = [], panel = null, canCollect = false, samities = [], pickerSamityId = null, pickerMembers = [] }) {
    const { props } = usePage();
    const [searching, setSearching] = useState(false);
    const [memberFilter, setMemberFilter] = useState('');
    const form = useForm({
        loan_id: panel?.id ?? '', amount: panel?.due_amount ?? '',
        savings_amount: panel?.savings?.expected ?? '', savings_withdrawal_amount: '',
        lts_amount: panel?.lts?.expected ?? '',
    });

    // A fresh panel (first open, another loan, or the post-collect
    // redirect) re-prefills all three parts with the current figures —
    // the typed values never survive into a different panel. The
    // withdrawal field always starts empty: paying money OUT is a
    // deliberate entry, never a prefilled suggestion.
    useEffect(() => {
        if (panel) form.setData({
            loan_id: panel.id, amount: panel.due_amount,
            savings_amount: panel.savings?.expected ?? '', savings_withdrawal_amount: '',
            lts_amount: panel.lts?.expected ?? '',
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [panel?.id, panel?.due_amount, panel?.savings?.expected, panel?.lts?.expected]);

    const search = (e) => {
        e.preventDefault();
        router.get('/collection/collect-loan', { loan: e.target.elements.loan.value }, {
            preserveState: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });
    };

    const pickSamity = (samityId) => {
        setMemberFilter('');
        router.get('/collection/collect-loan', samityId ? { samity_id: samityId } : {}, {
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

    // Daily loan, daily collection: nothing due on/before today means
    // there is no installment to collect — the next (future)
    // installment is never offered as an advance. Savings-only
    // collection stays possible when the member holds a savings or
    // LTS account (the 3-part panel), so the Collect button locks
    // only when there is genuinely nothing to collect.
    const noDue = panel ? !(Number(panel.due_amount) > 0) : false;
    const nothingToCollect = noDue && !panel?.savings && !panel?.lts;

    const visiblePickerMembers = pickerMembers.filter((m) => {
        const needle = memberFilter.trim().toLowerCase();
        return needle === '' || m.member_no.toLowerCase().includes(needle) || m.name.toLowerCase().includes(needle);
    });

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('collect_loan')}</h1>
            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label={t('samity')}>
                        <select className={inputCls} value={pickerSamityId ?? ''} onChange={(e) => pickSamity(e.target.value)}>
                            <option value="">—</option>
                            {samities.map((s) => <option key={s.id} value={s.id}>{s.code} — {s.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('search')}>
                        <input className={inputCls} value={memberFilter} onChange={(e) => setMemberFilter(e.target.value)} placeholder={`${t('member_no')} / ${t('member_name')}`} />
                    </Field>
                </div>
                {pickerSamityId && (
                    <div className="mt-3 max-h-96 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
                        <table className="w-full text-left text-sm">
                            <thead>
                                <tr className="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-800/50">
                                    <th className="px-3 py-2 font-medium">{t('serial_no')}</th>
                                    <th className="px-3 py-2 font-medium">{t('member_no')}</th>
                                    <th className="px-3 py-2 font-medium">{t('member_name')}</th>
                                    <th className="px-3 py-2 font-medium">{t('loan_list')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {visiblePickerMembers.map((m) => (
                                    <tr key={m.id} className="border-b border-zinc-100 dark:border-zinc-800">
                                        <td className="px-3 py-2">{m.serial}</td>
                                        <td className="px-3 py-2">{m.member_no}</td>
                                        <td className="px-3 py-2">{m.name}</td>
                                        <td className="px-3 py-2">
                                            <span className="flex flex-wrap gap-2">
                                                {m.loans.map((l) => (
                                                    <Link key={l.id} href={`/collection/collect-loan?loan_id=${l.id}`} className="rounded-lg bg-emerald-50 px-2 py-1 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-300">
                                                        {l.loan_number} · {t('due_amount')}: {l.due_amount}
                                                    </Link>
                                                ))}
                                                {m.loans.length === 0 && <span className="text-zinc-400">—</span>}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                                {visiblePickerMembers.length === 0 && (
                                    <tr><td className="px-3 py-3" colSpan={4}>{t('no_data_found')}</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
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
                        {canCollect && noDue && (
                            <p className="mt-4 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-700 dark:bg-sky-900/30 dark:text-sky-300">{t('loan_no_due_today')}</p>
                        )}
                        {canCollect && (
                            <form onSubmit={collect} className="mt-4 flex flex-wrap items-end gap-3">
                                <div className="min-w-40 flex-1 sm:max-w-xs">
                                    <Field label={t('due_amount')} error={form.errors.amount} required>
                                        <input type="number" step="0.01" min="0" className={inputCls} value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} required disabled={noDue} />
                                    </Field>
                                </div>
                                {panel.savings && (
                                    <div className="min-w-40 flex-1 sm:max-w-xs">
                                        <Field label={`${t('current_savings')} (${panel.savings.account_number})`} error={form.errors.savings_amount}>
                                            <input type="number" step="0.01" min="0" className={inputCls} value={form.data.savings_amount} onChange={(e) => form.setData('savings_amount', e.target.value)} />
                                        </Field>
                                        <span className="mt-1 block text-xs text-zinc-500">{t('balance')}: {panel.savings.balance}</span>
                                    </div>
                                )}
                                {panel.savings && (
                                    <div className="min-w-40 flex-1 sm:max-w-xs">
                                        <Field label={`${t('withdrawal')} (${panel.savings.account_number})`} error={form.errors.savings_withdrawal_amount}>
                                            <input type="number" step="0.01" min="0" className={inputCls} value={form.data.savings_withdrawal_amount} onChange={(e) => form.setData('savings_withdrawal_amount', e.target.value)} />
                                        </Field>
                                        <span className="mt-1 block text-xs text-zinc-500">{t('field_withdrawal_hint')}</span>
                                    </div>
                                )}
                                {panel.lts && (
                                    <div className="min-w-40 flex-1 sm:max-w-xs">
                                        <Field label={`${t('long_term_savings')} (${panel.lts.account_number})`} error={form.errors.lts_amount}>
                                            <input type="number" step="0.01" min="0" className={inputCls} value={form.data.lts_amount} onChange={(e) => form.setData('lts_amount', e.target.value)} />
                                        </Field>
                                        <span className="mt-1 block text-xs text-zinc-500">{t('balance')}: {panel.lts.balance}</span>
                                    </div>
                                )}
                                <PrimaryButton type="submit" loading={form.processing} disabled={nothingToCollect}>{t('collect')}</PrimaryButton>
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
