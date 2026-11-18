import { router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { Card, Field, PrimaryButton, SelectInput } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/**
 * দৈনিক অফিস তথ্য রেজিস্ট্রার — the manager's daily register
 * (client round 3): one row per business day of the branch + month,
 * grouped in 6-day weeks exactly as the paper sheet — serials
 * ০১–০৬ restart each week, every week closes with a মোট row (the
 * week) and a সর্বমোট row (cumulative through that week), and the
 * last column is the ম্যানেজারের স্বাক্ষর column. 25 columns, the
 * sheet's order. Every figure is computed from the day's real
 * postings — nothing is typed in.
 */
const bnDigits = (v) => String(v).replace(/\d/g, (d) => '০১২৩৪৫৬৭৮৯'[Number(d)]);

function DayRow({ row, serial }) {
    return (
        <tr className="border-b border-zinc-100 dark:border-zinc-800">
            <td className="px-2 py-1.5">{bnDigits(String(serial).padStart(2, '0'))}</td>
            <td className="whitespace-nowrap px-2 py-1.5">{row.date}</td>
            <td className="px-2 py-1.5 text-right">{row.savings_due}</td>
            <td className="px-2 py-1.5 text-right">{row.savings_collected}</td>
            <td className="px-2 py-1.5 text-right">{row.admission_count}</td>
            <td className="px-2 py-1.5 text-right">{row.admission_amount}</td>
            <td className="px-2 py-1.5 text-right">{row.insurance_count}</td>
            <td className="px-2 py-1.5 text-right">{row.insurance_amount}</td>
            <td className="px-2 py-1.5 text-right">{row.withdrawal_count}</td>
            <td className="px-2 py-1.5 text-right">{row.withdrawal_amount}</td>
            <td className="px-2 py-1.5 text-right">{row.member_exit_count}</td>
            <td className="px-2 py-1.5 text-right">{row.other_fees}</td>
            <td className="px-2 py-1.5 text-right font-semibold">{row.total_in}</td>
            <td className="px-2 py-1.5 text-right">{row.disbursed_count}</td>
            <td className="px-2 py-1.5 text-right">{row.disbursed_amount}</td>
            <td className="px-2 py-1.5 text-right">{row.settled_count}</td>
            <td className="px-2 py-1.5 text-right">{row.settled_amount}</td>
            <td className="px-2 py-1.5 text-right">{row.installment_due}</td>
            <td className="px-2 py-1.5 text-right">{row.installment_collected}</td>
            <td className="px-2 py-1.5 text-right font-semibold">{row.total_loan}</td>
            <td className="px-2 py-1.5 text-right font-semibold">{row.grand_total}</td>
            <td className="px-2 py-1.5 text-right">{row.loan_outstanding}</td>
            <td className="px-2 py-1.5 text-right">{row.other_expenses}</td>
            <td className="px-2 py-1.5 text-right">{row.cash_in_hand}</td>
            <td className="px-2 py-1.5"></td>
        </tr>
    );
}

function TotalRow({ row, label, strong = false }) {
    return (
        <tr className={`border-b border-zinc-200 font-semibold dark:border-zinc-700 ${strong ? 'bg-zinc-100 dark:bg-zinc-800' : 'bg-zinc-50 dark:bg-zinc-800/60'}`}>
            <td className="px-2 py-1.5" colSpan={2}>{label}</td>
            <td className="px-2 py-1.5 text-right">{row.savings_due}</td>
            <td className="px-2 py-1.5 text-right">{row.savings_collected}</td>
            <td className="px-2 py-1.5 text-right">{row.admission_count}</td>
            <td className="px-2 py-1.5 text-right">{row.admission_amount}</td>
            <td className="px-2 py-1.5 text-right">{row.insurance_count}</td>
            <td className="px-2 py-1.5 text-right">{row.insurance_amount}</td>
            <td className="px-2 py-1.5 text-right">{row.withdrawal_count}</td>
            <td className="px-2 py-1.5 text-right">{row.withdrawal_amount}</td>
            <td className="px-2 py-1.5 text-right">{row.member_exit_count}</td>
            <td className="px-2 py-1.5 text-right">{row.other_fees}</td>
            <td className="px-2 py-1.5 text-right">{row.total_in}</td>
            <td className="px-2 py-1.5 text-right">{row.disbursed_count}</td>
            <td className="px-2 py-1.5 text-right">{row.disbursed_amount}</td>
            <td className="px-2 py-1.5 text-right">{row.settled_count}</td>
            <td className="px-2 py-1.5 text-right">{row.settled_amount}</td>
            <td className="px-2 py-1.5 text-right">{row.installment_due}</td>
            <td className="px-2 py-1.5 text-right">{row.installment_collected}</td>
            <td className="px-2 py-1.5 text-right">{row.total_loan}</td>
            <td className="px-2 py-1.5 text-right">{row.grand_total}</td>
            <td className="px-2 py-1.5 text-right">{row.loan_outstanding}</td>
            <td className="px-2 py-1.5 text-right">{row.other_expenses}</td>
            <td className="px-2 py-1.5 text-right">{row.cash_in_hand}</td>
            <td className="px-2 py-1.5"></td>
        </tr>
    );
}

export default function ManagerRegister({ branches = [], branch = null, month = '', weeks = [] }) {
    const [branchId, setBranchId] = useState(branch?.id ?? '');
    const [monthValue, setMonthValue] = useState(month);
    const [searching, setSearching] = useState(false);

    const run = (e) => {
        e.preventDefault();
        router.get('/reports/manager-register', { branch_id: branchId, month: monthValue }, {
            preserveState: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });
    };
    const pdfUrl = `/reports/manager-register/pdf?branch_id=${branchId}&month=${monthValue}`;

    const groupHead = [
        [t('reg_serial_no'), 1], [t('date'), 1],
        [t('reg_savings_account'), 2],
        [t('reg_member_admission'), 2],
        [t('reg_loan_insurance'), 2],
        [t('withdrawal'), 2],
        [t('reg_member_exit'), 1],
        [t('reg_other'), 1],
        [t('total'), 1],
        [t('reg_disbursed_loans'), 2],
        [t('reg_loans_settled'), 2],
        [t('reg_installment_collected'), 2],
        [t('total'), 1],
        [t('reg_grand_total'), 1],
        [t('reg_loan_outstanding'), 1],
        [t('reg_other_expenses'), 1],
        [t('reg_cash_in_hand'), 1],
        [t('reg_manager_signature'), 1],
    ];
    const subHead = [
        '', '',
        t('reg_savings_due'), t('reg_savings_collected'),
        t('reg_persons'), t('amount'),
        t('reg_persons'), t('amount'),
        t('reg_persons'), t('amount'),
        t('reg_persons'),
        '', '',
        t('reg_persons'), t('amount'),
        t('reg_persons'), t('amount'),
        t('reg_installment_due'), t('reg_installment_collected'),
        '', '', '', '', '', '',
    ];

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('daily_office_register')}</h1>

            <Card className="mb-4">
                <form onSubmit={run} className="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Field label={t('branch')}>
                        <SelectInput value={branchId} onChange={(e) => setBranchId(e.target.value)}>
                            {branches.map((b) => <option key={b.id} value={b.id}>{b.code} — {b.name}</option>)}
                        </SelectInput>
                    </Field>
                    <Field label={t('month')}>
                        <input type="month" className="min-h-[44px] w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-emerald-600 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100" value={monthValue} onChange={(e) => setMonthValue(e.target.value)} />
                    </Field>
                    <div className="flex gap-2">
                        <PrimaryButton type="submit" loading={searching}>{t('view')}</PrimaryButton>
                        <a href={pdfUrl} target="_blank" rel="noreferrer" className="inline-flex min-h-[44px] items-center rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium dark:border-zinc-700">{t('print')}</a>
                    </div>
                </form>
            </Card>

            <Card>
                <p className="mb-3 text-sm text-zinc-500">
                    {branch ? `${branch.code} — ${branch.name}` : ''} · {month}
                </p>
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[1500px] text-left text-[13px]">
                        <thead>
                            <tr className="border-b border-zinc-200 text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                                {groupHead.map(([label, span], i) => (
                                    <th key={i} colSpan={span} className="px-2 py-1.5 text-center font-medium">{label}</th>
                                ))}
                            </tr>
                            <tr className="border-b border-zinc-200 text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                                {subHead.map((label, i) => (
                                    <th key={i} className={`px-2 py-1.5 font-medium ${i >= 2 ? 'text-right' : ''}`}>{label}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {weeks.map((week, wi) => (
                                <Fragment key={wi}>
                                    {week.rows.map((row, ri) => <DayRow key={`${wi}-${row.date}`} row={row} serial={ri + 1} />)}
                                    <TotalRow row={week.total} label={t('total')} />
                                    <TotalRow row={week.cumulative} label={t('reg_grand_total')} strong />
                                </Fragment>
                            ))}
                            {weeks.length === 0 && (
                                <tr><td colSpan={25} className="px-2 py-6 text-center text-zinc-500">—</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </Card>
        </AppLayout>
    );
}
