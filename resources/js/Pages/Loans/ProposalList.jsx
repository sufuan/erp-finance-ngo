import { Link, router } from '@inertiajs/react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Loan proposal list + approval queue (Step 16). */
export default function ProposalList({ proposals, samities, officers, filters, canApprove }) {
    const search = (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        router.get('/loans/proposals', Object.fromEntries(fd.entries()), { preserveState: true });
    };
    const decide = (id, action) => router.post(`/loans/proposals/${id}/${action}`, {}, { preserveScroll: true });

    return (
        <AppLayout>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold">{t('loan_proposal_list')}</h1>
                <Link href="/loans/proposals/create" className="inline-flex min-h-[44px] items-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white">{t('loan_proposal')}</Link>
            </div>
            <Card className="mb-4">
                <form onSubmit={search} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label={t('samity')}>
                        <select name="samity_id" className={inputCls} defaultValue={filters.samity_id ?? ''}>
                            <option value="">{t('all')}</option>
                            {samities.map((s) => <option key={s.id} value={s.id}>{s.samity_code} — {s.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('field_officer')}>
                        <select name="field_officer_id" className={inputCls} defaultValue={filters.field_officer_id ?? ''}>
                            <option value="">{t('all')}</option>
                            {officers.map((o) => <option key={o.id} value={o.id}>{o.employee_code} — {o.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('member_no')}>
                        <input name="member" className={inputCls} defaultValue={filters.member ?? ''} />
                    </Field>
                    <Field label={t('status')}>
                        <select name="status" className={inputCls} defaultValue={filters.status ?? ''}>
                            <option value="">{t('all')}</option>
                            <option value="Submitted">{t('submitted')}</option>
                            <option value="Approved">{t('approved')}</option>
                            <option value="Rejected">{t('rejected')}</option>
                        </select>
                    </Field>
                    <Field label={`${t('from_date')} *`}>
                        <input type="date" name="from_date" className={inputCls} defaultValue={filters.from_date ?? ''} />
                    </Field>
                    <Field label={`${t('upto_date')} *`}>
                        <input type="date" name="to_date" className={inputCls} defaultValue={filters.to_date ?? ''} />
                    </Field>
                    <div className="flex items-end">
                        <PrimaryButton type="submit">{t('search')}</PrimaryButton>
                    </div>
                </form>
            </Card>
            <Card>
                <ResponsiveTable head={[t('branch'), t('samity'), t('member_no'), t('account_type'), t('profit_rate'), t('proposal_date'), t('loan_purpose'), t('loan_amount'), t('frequency'), t('installment_amount'), t('installment_from_date'), t('no_of_installments'), t('installment_expire_date'), t('status'), t('actions')]}>
                    {proposals.map((p) => (
                        <tr key={p.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{p.branch_code ?? '—'}</td>
                            <td className="px-3 py-2">{p.samity ?? '—'}</td>
                            <td className="px-3 py-2">{p.member ?? '—'}</td>
                            <td className="px-3 py-2">{p.account_type}</td>
                            <td className="px-3 py-2">{p.profit_rate}</td>
                            <td className="px-3 py-2">{p.proposal_date}</td>
                            <td className="px-3 py-2">{p.loan_purpose ?? '—'}</td>
                            <td className="px-3 py-2">{p.loan_amount}</td>
                            <td className="px-3 py-2">{p.frequency}</td>
                            <td className="px-3 py-2">{p.installment_amount}</td>
                            <td className="px-3 py-2">{p.installment_from_date}</td>
                            <td className="px-3 py-2">{p.no_of_installments}</td>
                            <td className="px-3 py-2">{p.installment_expire_date}</td>
                            <td className="px-3 py-2">{t(p.status.toLowerCase())}</td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                {p.status === 'Submitted' && canApprove && (
                                    <>
                                        <button type="button" onClick={() => decide(p.id, 'approve')} className="rounded-lg px-2 py-1 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/20">{t('approve')}</button>
                                        <button type="button" onClick={() => decide(p.id, 'reject')} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('reject')}</button>
                                    </>
                                )}
                                {p.status === 'Approved' && (
                                    <Link href={`/loans/opening?proposal_id=${p.id}`} className="rounded-lg px-2 py-1 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/20">{t('open_loan')}</Link>
                                )}
                            </td>
                        </tr>
                    ))}
                    {proposals.length === 0 && <tr><td className="px-3 py-3" colSpan={15}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
