import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Card, PrimaryButton, ResponsiveTable } from './ui';
import { t } from '../lib/i18n';

const num = (v) => {
    const n = parseFloat(v);
    return Number.isFinite(n) ? n : 0;
};
const fmt = (n) => n.toFixed(2);

/**
 * Collection posting grid (Step 21): one row per member, one editable
 * amount cell per account the member holds (deposit accounts, loans,
 * share), prefilled with the receivable. The right-hand Summary card
 * (Total Receivable / Loan Receive / Deposit Receive / Total Receive /
 * Total Dues) updates live as amounts change. On submit only rows with
 * an amount > 0 are sent — the server re-validates and posts the whole
 * sheet atomically through the deposit/loan engines.
 */
export default function CollectionGrid({ grid, postUrl, submitLabel, processing: externalProcessing, errors, extra = {} }) {
    const [amounts, setAmounts] = useState(() => {
        const initial = {};
        grid.forEach((row) => {
            row.accounts.forEach((a) => {
                initial[`${row.member_id}:${a.account_type}:${a.account_id}`] = a.amount ?? '0.00';
            });
        });
        return initial;
    });
    const [posting, setPosting] = useState(false);

    const summary = useMemo(() => {
        let receivable = 0;
        let loan = 0;
        let deposit = 0;
        let share = 0;
        grid.forEach((row) => {
            row.accounts.forEach((a) => {
                receivable += num(a.receivable);
                const amt = num(amounts[`${row.member_id}:${a.account_type}:${a.account_id}`]);
                if (a.account_type === 'loan') loan += amt;
                else if (a.account_type === 'deposit') deposit += amt;
                else share += amt;
            });
        });
        const total = loan + deposit + share;
        return { receivable, loan, deposit, share, total, dues: receivable - total };
    }, [amounts, grid]);

    const setAmount = (key, value) => setAmounts((prev) => ({ ...prev, [key]: value }));

    const submit = (e) => {
        e.preventDefault();
        const lines = [];
        grid.forEach((row) => {
            row.accounts.forEach((a) => {
                const amt = amounts[`${row.member_id}:${a.account_type}:${a.account_id}`];
                if (num(amt) > 0) {
                    lines.push({ member_id: row.member_id, account_type: a.account_type, account_id: a.account_id, amount: amt });
                }
            });
        });
        router.post(postUrl, { ...extra, lines }, {
            preserveScroll: true,
            onStart: () => setPosting(true),
            onFinish: () => setPosting(false),
        });
    };

    // Account columns: union of account labels across members (simple
    // sheet style: one amount column per account kind held).
    const accountKinds = useMemo(() => {
        const kinds = new Map();
        grid.forEach((row) => {
            row.accounts.forEach((a) => {
                const kindKey = a.account_type === 'deposit' ? `deposit:${a.product_code ?? 'SAVINGS'}` : a.account_type;
                if (!kinds.has(kindKey)) {
                    kinds.set(kindKey, { kindKey, label: a.account_type === 'deposit' ? a.product_label : a.account_type === 'loan' ? t('loan') : t('share') });
                }
            });
        });
        return [...kinds.values()];
    }, [grid]);

    const cellFor = (row, kindKey) =>
        row.accounts.filter((a) => (a.account_type === 'deposit' ? `deposit:${a.product_code ?? 'SAVINGS'}` : a.account_type) === kindKey);

    return (
        <form onSubmit={submit}>
            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2 overflow-x-auto">
                    <ResponsiveTable head={['#', t('member_no'), t('member_name'), ...accountKinds.map((k) => k.label), t('total')]}>
                        {grid.map((row, idx) => {
                            const rowTotal = row.accounts.reduce((s, a) => s + num(amounts[`${row.member_id}:${a.account_type}:${a.account_id}`]), 0);
                            return (
                                <tr key={row.member_id} className="border-b border-zinc-100 dark:border-zinc-800">
                                    <td className="px-3 py-2">{idx + 1}</td>
                                    <td className="px-3 py-2 whitespace-nowrap">{row.member_no}</td>
                                    <td className="px-3 py-2 whitespace-nowrap font-medium">{row.member_name}</td>
                                    {accountKinds.map((kind) => {
                                        const cells = cellFor(row, kind.kindKey);
                                        return (
                                            <td key={kind.kindKey} className="px-3 py-2">
                                                {cells.map((a) => {
                                                    const key = `${row.member_id}:${a.account_type}:${a.account_id}`;
                                                    return (
                                                        <div key={key} className="mb-1 last:mb-0">
                                                            <input
                                                                type="number" step="0.01" min="0"
                                                                aria-label={`${a.label} ${t('amount')}`}
                                                                className="w-28 rounded-lg border border-zinc-300 px-2 py-1.5 text-right text-sm dark:border-zinc-700 dark:bg-zinc-900"
                                                                value={amounts[key] ?? ''}
                                                                onChange={(e) => setAmount(key, e.target.value)}
                                                            />
                                                            <span className="block text-[11px] text-zinc-500">
                                                                {a.label} · {t('receivable')}: {a.receivable}
                                                            </span>
                                                        </div>
                                                    );
                                                })}
                                            </td>
                                        );
                                    })}
                                    <td className="px-3 py-2 text-right font-medium">{fmt(rowTotal)}</td>
                                </tr>
                            );
                        })}
                    </ResponsiveTable>
                    {grid.length === 0 && <p className="py-4 text-sm text-zinc-500">{t('no_data_found')}</p>}
                </Card>

                <Card className="h-fit">
                    <h2 className="mb-3 font-semibold">{t('summary')}</h2>
                    <dl className="space-y-2 text-sm">
                        <div className="flex justify-between"><dt className="text-zinc-500">{t('total_receivable')}</dt><dd className="font-medium">{fmt(summary.receivable)}</dd></div>
                        <div className="flex justify-between"><dt className="text-zinc-500">{t('loan_receive')}</dt><dd>{fmt(summary.loan)}</dd></div>
                        <div className="flex justify-between"><dt className="text-zinc-500">{t('deposit_receive')}</dt><dd>{fmt(summary.deposit)}</dd></div>
                        <div className="flex justify-between"><dt className="text-zinc-500">{t('share_receive')}</dt><dd>{fmt(summary.share)}</dd></div>
                        <div className="flex justify-between border-t border-zinc-200 pt-2 font-semibold text-emerald-600 dark:border-zinc-700"><dt>{t('total_receive')}</dt><dd>{fmt(summary.total)}</dd></div>
                        <div className="flex justify-between font-semibold text-red-600"><dt>{t('total_dues')}</dt><dd>{fmt(summary.dues)}</dd></div>
                    </dl>
                    {errors?.lines && (
                        <ul className="mt-3 space-y-1 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-300">
                            {(Array.isArray(errors.lines) ? errors.lines : [errors.lines]).map((msg, i) => <li key={i}>{msg}</li>)}
                        </ul>
                    )}
                    <PrimaryButton type="submit" loading={posting || externalProcessing} disabled={summary.total <= 0} className="mt-4 w-full">
                        {submitLabel}
                    </PrimaryButton>
                </Card>
            </div>
        </form>
    );
}
