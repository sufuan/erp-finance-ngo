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
 * Collection — the ONE unified collection screen (client round
 * 2026-11-19): pick a samity, click a member row, and his panel
 * shows only the rows he has — installment (when he has a loan),
 * general savings, field withdrawal and long-term savings — each
 * prefilled with its due (যোগ্য) amount, posted together in one
 * batch through CollectionService. Each member row also shows
 * whether he has been collected today (আদায় হয়েছে + total) or
 * not (আদায় হয়নি). There is NO separate loan/member finder box:
 * the list's search field filters, the row click opens the panel
 * (owner refinement, 2026-11-19). The classic loan panel stays
 * reachable by loan_id deep links for the loan-by-loan flow. The
 * standalone Member Collection page was retired into this screen.
 */
export default function CollectLoan({ query = '', results = [], panel = null, memberPanel = null, canCollect = false, samities = [], pickerSamityId = null, pickerMembers = [] }) {
    const { props } = usePage();
    const [memberFilter, setMemberFilter] = useState('');
    const form = useForm({
        loan_id: panel?.id ?? '', amount: panel?.due_amount ?? '',
        savings_amount: panel?.savings?.expected ?? '', savings_withdrawal_amount: '',
        lts_amount: panel?.lts?.expected ?? '',
    });
    const mform = useForm({
        member_id: memberPanel?.member_id ?? '', amount: memberPanel?.loan?.due_amount ?? '',
        savings_amount: memberPanel?.savings?.expected ?? '', savings_withdrawal_amount: '',
        lts_amount: memberPanel?.lts?.expected ?? '',
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

    // Same re-prefill contract for the unified member panel: a fresh
    // member (or the post-collect redirect) resets every row to the
    // current due figures; the withdrawal always starts empty.
    useEffect(() => {
        if (memberPanel) mform.setData({
            member_id: memberPanel.member_id, amount: memberPanel.loan?.due_amount ?? '',
            savings_amount: memberPanel.savings?.expected ?? '', savings_withdrawal_amount: '',
            lts_amount: memberPanel.lts?.expected ?? '',
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [memberPanel?.member_id, memberPanel?.loan?.due_amount, memberPanel?.savings?.expected, memberPanel?.lts?.expected]);

    const collectMember = (e) => {
        e.preventDefault();
        mform.post('/collection/collect-loan', { preserveScroll: true });
    };

    // Clicking a member row opens his collection panel directly —
    // there is deliberately NO separate loan/member finder box on
    // this screen (owner refinement, 2026-11-19): the search field
    // above filters the list, the row click opens the panel. Clicks
    // on the row's own links (a loan chip) keep their own target.
    const openMember = (e, memberId) => {
        if (e.target.closest('a')) return;
        router.get('/collection/collect-loan', { member_id: memberId }, { preserveState: true });
    };

    const pickSamity = (samityId) => {
        setMemberFilter('');
        router.get('/collection/collect-loan', samityId ? { samity_id: samityId } : {}, {
            preserveState: true,
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

    // Unified member panel state: the installment row exists only
    // when the member has an active loan; the Collect button locks
    // only when there is genuinely nothing to collect.
    const mLoan = memberPanel?.loan ?? null;
    const mLoanDue = mLoan ? Number(mLoan.due_amount) > 0 : false;
    const mNoDue = !!mLoan && !mLoanDue;
    const mNothingToCollect = !mLoanDue && !memberPanel?.savings && !memberPanel?.lts;
    const mStats = mLoan ? [
        [t('total_installments'), mLoan.summary.total],
        [t('installments_collected'), mLoan.summary.paid_count],
        [t('installments_remaining'), mLoan.summary.remaining_count],
        [t('collected_amount'), mLoan.summary.collected_amount],
        [t('remaining_amount'), mLoan.summary.remaining_amount],
    ] : [];

    const visiblePickerMembers = pickerMembers.filter((m) => {
        const needle = memberFilter.trim().toLowerCase();
        return needle === '' || m.member_no.toLowerCase().includes(needle) || m.name.toLowerCase().includes(needle);
    });

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('collection')}</h1>
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
                                    <th className="px-3 py-2 font-medium">{t('status')}</th>
                                    <th className="px-3 py-2 font-medium">{t('collection')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {visiblePickerMembers.map((m) => (
                                    <tr key={m.id} onClick={(e) => openMember(e, m.id)} className="cursor-pointer border-b border-zinc-100 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/50">
                                        <td className="px-3 py-2">{m.serial}</td>
                                        <td className="px-3 py-2">{m.member_no}</td>
                                        <td className="px-3 py-2">
                                            <Link href={`/collection/collect-loan?member_id=${m.id}`} className="font-medium text-emerald-700 hover:underline dark:text-emerald-300">
                                                {m.name}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2">
                                            {m.collected_today
                                                ? <span className="rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">{t('collected_today')}: {m.collected_today_amount}</span>
                                                : <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{t('not_collected_today')}</span>}
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className="flex flex-wrap gap-2">
                                                {m.savings && (
                                                    <Link href={`/collection/collect-loan?member_id=${m.id}`} className="rounded-lg bg-sky-50 px-2 py-1 text-sky-700 hover:bg-sky-100 dark:bg-sky-900/30 dark:text-sky-300">
                                                        {t('general_savings')} · {t('due_amount')}: {m.savings.expected}
                                                    </Link>
                                                )}
                                                {m.lts && (
                                                    <Link href={`/collection/collect-loan?member_id=${m.id}`} className="rounded-lg bg-violet-50 px-2 py-1 text-violet-700 hover:bg-violet-100 dark:bg-violet-900/30 dark:text-violet-300">
                                                        {t('long_term_savings')} · {t('due_amount')}: {m.lts.expected}
                                                    </Link>
                                                )}
                                                {m.loans.map((l) => (
                                                    <Link key={l.id} href={`/collection/collect-loan?loan_id=${l.id}`} className="rounded-lg bg-emerald-50 px-2 py-1 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-300">
                                                        {l.loan_number} · {t('due_amount')}: {l.due_amount}
                                                    </Link>
                                                ))}
                                                {!m.savings && !m.lts && m.loans.length === 0 && <span className="text-zinc-400">—</span>}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                                {visiblePickerMembers.length === 0 && (
                                    <tr><td className="px-3 py-3" colSpan={5}>{t('no_data_found')}</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            {memberPanel && (
                <>
                    <Card className="mb-4">
                        <div className="grid gap-3 text-sm sm:grid-cols-3">
                            <p><span className="block text-xs text-zinc-500">{t('member_name')}</span><span className="font-medium">{memberPanel.member_name}</span> ({memberPanel.member_no})</p>
                            <p><span className="block text-xs text-zinc-500">{t('samity')}</span>{memberPanel.samity}</p>
                            <p><span className="block text-xs text-zinc-500">{t('business_date')}</span>{memberPanel.business_date}</p>
                            {mLoan && (
                                <p><span className="block text-xs text-zinc-500">{t('loan_number')}</span><span className="font-medium">{mLoan.loan_number}</span>
                                    <span className="block text-xs text-zinc-500">{t('outstanding')}: {mLoan.outstanding.total}</span>
                                </p>
                            )}
                            {memberPanel.savings && (
                                <p><span className="block text-xs text-zinc-500">{t('general_savings')}</span>{memberPanel.savings.account_number}
                                    <span className="block text-xs text-zinc-500">{t('balance')}: {memberPanel.savings.balance}</span>
                                </p>
                            )}
                            {memberPanel.lts && (
                                <p><span className="block text-xs text-zinc-500">{t('long_term_savings')}</span>{memberPanel.lts.account_number}
                                    <span className="block text-xs text-zinc-500">{t('balance')}: {memberPanel.lts.balance}</span>
                                </p>
                            )}
                        </div>
                        {memberPanel.loans.length > 1 && (
                            <p className="mt-3 flex flex-wrap gap-2 text-sm">
                                {memberPanel.loans.map((l) => (
                                    <Link key={l.id} href={`/collection/collect-loan?loan_id=${l.id}`} className="rounded-lg bg-emerald-50 px-2 py-1 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-300">
                                        {l.loan_number} · {t('due_amount')}: {l.due_amount}
                                    </Link>
                                ))}
                            </p>
                        )}
                        {canCollect && mNoDue && (
                            <p className="mt-4 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-700 dark:bg-sky-900/30 dark:text-sky-300">{t('loan_no_due_today')}</p>
                        )}
                        {canCollect && (
                            <form onSubmit={collectMember} className="mt-4 flex flex-wrap items-end gap-3">
                                {mLoan && (
                                    <div className="min-w-40 flex-1 sm:max-w-xs">
                                        <Field label={`${t('due_amount')} (${mLoan.loan_number})`} error={mform.errors.amount} required>
                                            <input type="number" step="0.01" min="0" className={inputCls} value={mform.data.amount} onChange={(e) => mform.setData('amount', e.target.value)} required disabled={mNoDue} />
                                        </Field>
                                    </div>
                                )}
                                {memberPanel.savings && (
                                    <div className="min-w-40 flex-1 sm:max-w-xs">
                                        <Field label={`${t('general_savings')} (${memberPanel.savings.account_number})`} error={mform.errors.savings_amount}>
                                            <input type="number" step="0.01" min="0" className={inputCls} value={mform.data.savings_amount} onChange={(e) => mform.setData('savings_amount', e.target.value)} />
                                        </Field>
                                        <span className="mt-1 block text-xs text-zinc-500">{t('balance')}: {memberPanel.savings.balance}</span>
                                    </div>
                                )}
                                {memberPanel.savings && (
                                    <div className="min-w-40 flex-1 sm:max-w-xs">
                                        <Field label={`${t('withdrawal')} (${memberPanel.savings.account_number})`} error={mform.errors.savings_withdrawal_amount}>
                                            <input type="number" step="0.01" min="0" className={inputCls} value={mform.data.savings_withdrawal_amount} onChange={(e) => mform.setData('savings_withdrawal_amount', e.target.value)} />
                                        </Field>
                                        <span className="mt-1 block text-xs text-zinc-500">{t('field_withdrawal_hint')}</span>
                                    </div>
                                )}
                                {memberPanel.lts && (
                                    <div className="min-w-40 flex-1 sm:max-w-xs">
                                        <Field label={`${t('long_term_savings')} (${memberPanel.lts.account_number})`} error={mform.errors.lts_amount}>
                                            <input type="number" step="0.01" min="0" className={inputCls} value={mform.data.lts_amount} onChange={(e) => mform.setData('lts_amount', e.target.value)} />
                                        </Field>
                                        <span className="mt-1 block text-xs text-zinc-500">{t('balance')}: {memberPanel.lts.balance}</span>
                                    </div>
                                )}
                                <PrimaryButton type="submit" loading={mform.processing} disabled={mNothingToCollect}>{t('collect')}</PrimaryButton>
                            </form>
                        )}
                        {props.errors?.lines && <p className="mt-2 text-sm text-rose-600">{props.errors.lines}</p>}
                    </Card>

                    {mLoan && (
                        <>
                            <Card className="mb-4">
                                <dl className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                                    {mStats.map(([label, value]) => (
                                        <div key={label}>
                                            <dt className="text-xs text-zinc-500">{label}</dt>
                                            <dd className="text-lg font-semibold">{value}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </Card>

                            <Card>
                                <ResponsiveTable head={['#', t('due_date'), t('amount'), t('paid'), t('status')]}>
                                    {mLoan.installments.map((r) => (
                                        <tr key={r.no} className="border-b border-zinc-100 dark:border-zinc-800">
                                            <td className="px-3 py-2">{r.no}</td>
                                            <td className="px-3 py-2">{r.due_date}</td>
                                            <td className="px-3 py-2">{r.amount}</td>
                                            <td className="px-3 py-2">{r.paid}</td>
                                            <td className="px-3 py-2"><span className={`rounded px-1.5 py-0.5 text-xs font-medium ${STATUS_TONES[r.status]}`}>{t(r.status)}</span></td>
                                        </tr>
                                    ))}
                                    {mLoan.installments.length === 0 && <tr><td className="px-3 py-3" colSpan={5}>{t('no_data_found')}</td></tr>}
                                </ResponsiveTable>
                            </Card>
                        </>
                    )}
                </>
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
