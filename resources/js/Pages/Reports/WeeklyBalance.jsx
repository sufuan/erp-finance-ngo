import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, SelectInput, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/**
 * সাপ্তাহিক স্থিতির হিসাবের সিট — the weekly balance sheet
 * (client round 3): one row per samity of the branch for the
 * 6-business-day week ending at the chosen date — savings side
 * (previous balance + this week's deposits − withdrawals = total
 * balance) and installment side (previous dues − this week's
 * collection + new disbursements = total outstanding). The
 * tables mirror the paper sheet: ক্র. নং serials in Bangla
 * numerals, samity names under the শাখার নাম column, and a
 * "মোট =" row closing each table.
 */
const bnDigits = (v) => String(v).replace(/\d/g, (d) => '০১২৩৪৫৬৭৮৯'[Number(d)]);
export default function WeeklyBalance({ branches = [], branch = null, weekEnd = '', week = null, savings = [], savingsTotal = null, loans = [], loansTotal = null }) {
    const [branchId, setBranchId] = useState(branch?.id ?? '');
    const [endValue, setEndValue] = useState(weekEnd);
    const [searching, setSearching] = useState(false);

    const run = (e) => {
        e.preventDefault();
        router.get('/reports/weekly-balance', { branch_id: branchId, week_end: endValue }, {
            preserveState: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });
    };
    const pdfUrl = `/reports/weekly-balance/pdf?branch_id=${branchId}&week_end=${endValue}`;

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('weekly_balance_sheet')}</h1>

            <Card className="mb-4">
                <form onSubmit={run} className="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Field label={t('branch')}>
                        <SelectInput value={branchId} onChange={(e) => setBranchId(e.target.value)}>
                            {branches.map((b) => <option key={b.id} value={b.id}>{b.code} — {b.name}</option>)}
                        </SelectInput>
                    </Field>
                    <Field label={t('reg_week_ending')}>
                        <input type="date" className={inputCls} value={endValue} onChange={(e) => setEndValue(e.target.value)} />
                    </Field>
                    <div className="flex gap-2">
                        <PrimaryButton type="submit" loading={searching}>{t('view')}</PrimaryButton>
                        <a href={pdfUrl} target="_blank" rel="noreferrer" className="inline-flex min-h-[44px] items-center rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium dark:border-zinc-700">{t('print')}</a>
                    </div>
                </form>
                {week && (
                    <p className="mt-3 text-sm text-zinc-500">
                        {t('reg_week')}: {week.start} — {week.end}
                    </p>
                )}
            </Card>

            <Card className="mb-4">
                <h2 className="mb-3 font-semibold">{t('reg_savings_account')}</h2>
                <ResponsiveTable head={[t('reg_serial_short'), t('reg_branch_name'), t('reg_previous_balance'), t('reg_weekly_collection'), `${t('withdrawal')} (${t('reg_persons')})`, `${t('withdrawal')} (${t('amount')})`, t('reg_total_balance')]}>
                    {savings.map((row, i) => (
                        <tr key={row.samity_id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{bnDigits(i + 1)}.</td>
                            <td className="px-3 py-2">{row.samity_name}</td>
                            <td className="px-3 py-2 text-right">{row.previous_balance}</td>
                            <td className="px-3 py-2 text-right">{row.weekly_collection}</td>
                            <td className="px-3 py-2 text-right">{row.withdrawal_count}</td>
                            <td className="px-3 py-2 text-right">{row.withdrawal_amount}</td>
                            <td className="px-3 py-2 text-right font-semibold">{row.total_balance}</td>
                        </tr>
                    ))}
                    {savingsTotal && (
                        <tr className="bg-zinc-50 font-semibold dark:bg-zinc-800/60">
                            <td className="px-3 py-2"></td>
                            <td className="px-3 py-2">{t('reg_total_eq')}</td>
                            <td className="px-3 py-2 text-right">{savingsTotal.previous_balance}</td>
                            <td className="px-3 py-2 text-right">{savingsTotal.weekly_collection}</td>
                            <td className="px-3 py-2 text-right">{savingsTotal.withdrawal_count}</td>
                            <td className="px-3 py-2 text-right">{savingsTotal.withdrawal_amount}</td>
                            <td className="px-3 py-2 text-right">{savingsTotal.total_balance}</td>
                        </tr>
                    )}
                </ResponsiveTable>
            </Card>

            <Card>
                <h2 className="mb-3 font-semibold">{t('reg_installment_account')}</h2>
                <ResponsiveTable head={[t('reg_serial_short'), t('reg_branch_name'), t('reg_previous_dues'), t('reg_weekly_collection'), `${t('reg_loan_disbursement')} (${t('reg_persons')})`, `${t('reg_loan_disbursement')} (${t('amount')})`, t('reg_total_balance')]}>
                    {loans.map((row, i) => (
                        <tr key={row.samity_id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{bnDigits(i + 1)}.</td>
                            <td className="px-3 py-2">{row.samity_name}</td>
                            <td className="px-3 py-2 text-right">{row.previous_dues}</td>
                            <td className="px-3 py-2 text-right">{row.weekly_collection}</td>
                            <td className="px-3 py-2 text-right">{row.disbursed_count}</td>
                            <td className="px-3 py-2 text-right">{row.disbursed_amount}</td>
                            <td className="px-3 py-2 text-right font-semibold">{row.total_outstanding}</td>
                        </tr>
                    ))}
                    {loansTotal && (
                        <tr className="bg-zinc-50 font-semibold dark:bg-zinc-800/60">
                            <td className="px-3 py-2"></td>
                            <td className="px-3 py-2">{t('reg_total_eq')}</td>
                            <td className="px-3 py-2 text-right">{loansTotal.previous_dues}</td>
                            <td className="px-3 py-2 text-right">{loansTotal.weekly_collection}</td>
                            <td className="px-3 py-2 text-right">{loansTotal.disbursed_count}</td>
                            <td className="px-3 py-2 text-right">{loansTotal.disbursed_amount}</td>
                            <td className="px-3 py-2 text-right">{loansTotal.total_outstanding}</td>
                        </tr>
                    )}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
