import { ResponsiveTable } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/**
 * Director Dashboard (পরিচালক) — view-only consolidated overview:
 * big basic figures for today, this month and overall, all branches
 * combined, plus the same figures per branch. No actions, no forms.
 * Figures come from DirectorDashboardService (DCR/Dashboard definitions).
 */

const fmt = (v) => Number(v ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (v) => Number(v ?? 0).toLocaleString('en-US');

function Stat({ label, value }) {
    return (
        <div className="rounded-xl bg-white px-4 py-3 shadow-[var(--shadow-card)] dark:bg-zinc-900">
            <p className="text-[12px] font-medium uppercase tracking-wide text-ink-3 dark:text-zinc-500">{label}</p>
            <p className="mt-0.5 text-[22px] font-bold leading-tight text-ink dark:text-zinc-50">{value}</p>
        </div>
    );
}

function Section({ title, children }) {
    return (
        <section className="mb-6">
            <h2 className="mb-3 text-[16px] font-bold text-brand dark:text-zinc-100">{title}</h2>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{children}</div>
        </section>
    );
}

export default function DirectorDashboard({ asOn, today, month, totals, branches }) {
    return (
        <AppLayout>
            <div className="mb-4 flex flex-wrap items-end justify-between gap-2">
                <h1 className="text-xl font-semibold">{t('director_dashboard')}</h1>
                <p className="text-sm text-zinc-500">{t('dash.as_on')}: {asOn} · {t('dash.all_branches')}</p>
            </div>

            <Section title={t('director.today')}>
                <Stat label={t('director.deposit_collection')} value={fmt(today.deposit_collection)} />
                <Stat label={t('director.installment_due')} value={fmt(today.due_today)} />
                <Stat label={t('director.loan_collection')} value={fmt(today.loan_collection)} />
                <Stat label={t('director.disbursed')} value={fmt(today.disbursed)} />
                <Stat label={t('director.new_members')} value={num(today.new_members)} />
                <Stat label={t('acc_income')} value={fmt(today.income)} />
                <Stat label={t('acc_expenses')} value={fmt(today.expense)} />
            </Section>

            <Section title={t('director.this_month')}>
                <Stat label={t('director.deposit_collection')} value={fmt(month.deposit_collection)} />
                <Stat label={t('director.loan_collection')} value={fmt(month.loan_collection)} />
                <Stat label={t('director.disbursed')} value={fmt(month.disbursed)} />
                <Stat label={t('director.new_members')} value={num(month.new_members)} />
                <Stat label={t('acc_income')} value={fmt(month.income)} />
                <Stat label={t('acc_expenses')} value={fmt(month.expense)} />
            </Section>

            <Section title={t('director.totals')}>
                <Stat label={t('total_members')} value={num(totals.members)} />
                <Stat label={t('director.active_loans')} value={num(totals.active_loans)} />
                <Stat label={t('director.outstanding')} value={fmt(totals.outstanding)} />
                <Stat label={t('director.deposit_balance')} value={fmt(totals.deposit_balance)} />
            </Section>

            <section>
                <h2 className="mb-3 text-[16px] font-bold text-brand dark:text-zinc-100">{t('branch')}</h2>
                <div className="rounded-xl bg-white shadow-[var(--shadow-card)] dark:bg-zinc-900">
                    <ResponsiveTable head={[
                        t('branch'), t('business_date'), t('director.deposit_collection'), t('director.installment_due'),
                        t('director.loan_collection'), t('director.disbursed'), t('director.new_members'),
                        t('total_members'), t('director.outstanding'), t('director.deposit_balance'),
                    ]}>
                        {branches.map((b) => (
                            <tr key={b.id} className="border-b border-zinc-100 dark:border-zinc-800">
                                <td className="px-3 py-2 whitespace-nowrap">{b.code} — {b.name}</td>
                                <td className="px-3 py-2 whitespace-nowrap">{b.business_date}</td>
                                <td className="px-3 py-2">{fmt(b.today.deposit_collection)}</td>
                                <td className="px-3 py-2">{fmt(b.today.due_today)}</td>
                                <td className="px-3 py-2">{fmt(b.today.loan_collection)}</td>
                                <td className="px-3 py-2">{fmt(b.today.disbursed)}</td>
                                <td className="px-3 py-2">{num(b.today.new_members)}</td>
                                <td className="px-3 py-2">{num(b.totals.members)}</td>
                                <td className="px-3 py-2">{fmt(b.totals.outstanding)}</td>
                                <td className="px-3 py-2">{fmt(b.totals.deposit_balance)}</td>
                            </tr>
                        ))}
                        {branches.length === 0 && (
                            <tr><td className="px-3 py-3" colSpan={10}>{t('no_data_found')}</td></tr>
                        )}
                    </ResponsiveTable>
                </div>
            </section>
        </AppLayout>
    );
}
