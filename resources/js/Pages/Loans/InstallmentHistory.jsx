import { router } from '@inertiajs/react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Installment details / receive history (Step 18) — wide audit table. */
export default function InstallmentHistory({ member, memberQuery, loans, loanId, rows, filters }) {
    const lookup = (e) => { e.preventDefault(); router.get('/loans/receive/history', { member: e.target.elements.member.value }, { preserveState: true }); };
    const pickLoan = (id) => router.get('/loans/receive/history', { member: memberQuery, loan_id: id }, { preserveState: true, preserveScroll: true });
    const filterDates = (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        router.get('/loans/receive/history', { member: memberQuery, loan_id: loanId, from_date: fd.get('from_date'), to_date: fd.get('to_date') }, { preserveState: true });
    };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('installment_details')}</h1>
            <Card className="mb-4">
                <form onSubmit={lookup} className="flex flex-wrap items-end gap-3">
                    <div className="min-w-56 flex-1">
                        <Field label={`${t('member_no')} *`}>
                            <input name="member" className={inputCls} defaultValue={memberQuery} />
                        </Field>
                    </div>
                    <PrimaryButton type="submit">{t('search')}</PrimaryButton>
                </form>
                {member && (
                    <div className="mt-3 grid gap-3 sm:grid-cols-3">
                        <Field label={t('member_account')}>
                            <select className={inputCls} value={loanId ?? ''} onChange={(e) => pickLoan(e.target.value)}>
                                <option value="">—</option>
                                {loans.map((l) => <option key={l.id} value={l.id}>{l.loan_number} · {l.status}</option>)}
                            </select>
                        </Field>
                        <form onSubmit={filterDates} className="flex items-end gap-2 sm:col-span-2">
                            <Field label={`${t('from_date')} *`}>
                                <input type="date" name="from_date" className={inputCls} defaultValue={filters.from_date ?? ''} />
                            </Field>
                            <Field label={`${t('upto_date')} *`}>
                                <input type="date" name="to_date" className={inputCls} defaultValue={filters.to_date ?? ''} />
                            </Field>
                            <PrimaryButton type="submit">{t('search')}</PrimaryButton>
                        </form>
                    </div>
                )}
            </Card>
            <Card>
                <ResponsiveTable head={[t('branch'), t('loan_number'), t('member_no'), t('samity'), t('product'), t('profit_rate'), t('installment_amount'), t('date'), t('business_date'), t('installments'), t('investment_amount'), t('profit_amount'), t('penalty_charge'), t('total'), t('transfer_from_deposit'), t('document_number'), t('outstanding_principal'), t('outstanding_profit'), t('entry_by'), t('cancelled_by')]}>
                    {rows.map((r) => (
                        <tr key={r.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{r.branch_code ?? '—'}</td>
                            <td className="px-3 py-2">{r.loan_number}</td>
                            <td className="px-3 py-2">{r.member ?? '—'}</td>
                            <td className="px-3 py-2">{r.samity ?? '—'}</td>
                            <td className="px-3 py-2">{r.product}</td>
                            <td className="px-3 py-2">{r.profit_rate}</td>
                            <td className="px-3 py-2">{r.installment_amount}</td>
                            <td className="px-3 py-2">{r.receive_date}</td>
                            <td className="px-3 py-2">{r.business_date}</td>
                            <td className="px-3 py-2">{r.installments}</td>
                            <td className="px-3 py-2">{r.investment_amount}</td>
                            <td className="px-3 py-2">{r.profit_amount}</td>
                            <td className="px-3 py-2">{r.penalty_charge}</td>
                            <td className="px-3 py-2">{r.total_received}</td>
                            <td className="px-3 py-2">{r.transfer_from_deposit ? t('yes') : t('no')}</td>
                            <td className="px-3 py-2">{r.document_number ?? '—'}</td>
                            <td className="px-3 py-2">{r.outstanding_principal}</td>
                            <td className="px-3 py-2">{r.outstanding_profit}</td>
                            <td className="px-3 py-2">{r.entry_by ?? '—'} · {r.entry_at ? String(r.entry_at).slice(0, 10) : ''}</td>
                            <td className="px-3 py-2">{r.cancel_by ?? '—'}</td>
                        </tr>
                    ))}
                    {rows.length === 0 && <tr><td className="px-3 py-3" colSpan={20}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
