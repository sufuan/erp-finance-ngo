import { router } from '@inertiajs/react';
import { Banknote, Landmark, PiggyBank, Users } from 'lucide-react';
import AppLayout from '../Layouts/AppLayout';
import HomeHub from '../Components/HomeHub';
import { TONES } from '../lib/hubs';
import { t } from '../lib/i18n';

/**
 * Dashboard (Module 2 / Step 24): management overview on the post-login
 * home — KPI card groups, collection trend + deposit/loan charts, and
 * samity / field-officer summary tables, all computed server-side by
 * DashboardService against each branch's open business date. The mega-hub
 * (search + module tiles) stays below the overview.
 */

const fmt = (v) => Number(v ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (v) => Number(v ?? 0).toLocaleString('en-US');

function KpiGroup({ title, icon: Icon, tone, headline, rows }) {
    const toneDef = TONES[tone] ?? TONES.blue;
    return (
        <section className="overflow-hidden rounded-xl bg-white shadow-[var(--shadow-card)] dark:bg-zinc-900">
            <header className="flex items-center gap-2.5 border-b border-line px-4 py-3 dark:border-zinc-800">
                <span className="flex h-9 w-9 items-center justify-center rounded-lg" style={{ backgroundColor: toneDef.bg }}>
                    <Icon className="h-5 w-5" style={{ color: toneDef.fg }} strokeWidth={1.8} />
                </span>
                <h3 className="text-[15px] font-bold text-brand dark:text-zinc-100">{title}</h3>
            </header>
            <div className="px-4 py-3">
                <p className="text-[12px] font-medium uppercase tracking-wide text-ink-3 dark:text-zinc-500">{headline.label}</p>
                <p className="mt-0.5 text-[24px] font-bold leading-tight text-ink dark:text-zinc-50">{headline.value}</p>
                <dl className="mt-3 space-y-1.5">
                    {rows.map((r) => (
                        <div key={r.label} className="flex items-baseline justify-between gap-3 text-[13px]">
                            <dt className="text-ink-2 dark:text-zinc-400">{r.label}</dt>
                            <dd className={`font-semibold ${r.alert ? 'text-rose-600 dark:text-rose-400' : 'text-ink dark:text-zinc-100'}`}>{r.value}</dd>
                        </div>
                    ))}
                </dl>
            </div>
        </section>
    );
}

function TrendChart({ trend }) {
    const W = 640;
    const H = 190;
    const PAD = { l: 6, r: 6, t: 14, b: 22 };
    const max = Math.max(...trend.map((d) => Number(d.total)), 0);
    const bw = trend.length ? (W - PAD.l - PAD.r) / trend.length : W;
    return (
        <svg viewBox={`0 0 ${W} ${H}`} className="w-full" role="img" aria-label={t('dash.collection_trend')}>
            <line x1={PAD.l} y1={H - PAD.b} x2={W - PAD.r} y2={H - PAD.b} stroke="currentColor" className="text-line dark:text-zinc-700" />
            {trend.map((d, i) => {
                const h = max > 0 ? (Number(d.total) / max) * (H - PAD.t - PAD.b) : 0;
                const x = PAD.l + i * bw;
                return (
                    <g key={d.date}>
                        <rect x={x + 1} y={H - PAD.b - h} width={Math.max(bw - 2, 1)} height={h} rx="2" fill={TONES.blue.fg} opacity={i === trend.length - 1 ? 1 : 0.75}>
                            <title>{`${d.date}: ${fmt(d.total)}`}</title>
                        </rect>
                        {(i % 5 === 0 || i === trend.length - 1) && (
                            <text x={x + bw / 2} y={H - 6} textAnchor="middle" fontSize="10" fill="currentColor" className="text-ink-3 dark:text-zinc-500">
                                {d.date.slice(5)}
                            </text>
                        )}
                    </g>
                );
            })}
            {trend.length === 0 && (
                <text x={W / 2} y={H / 2} textAnchor="middle" fontSize="13" fill="currentColor" className="text-ink-3 dark:text-zinc-500">—</text>
            )}
        </svg>
    );
}

function MixDonut({ deposit }) {
    const parts = [
        { label: t('savings_balance'), value: Number(deposit.savings), color: TONES.green.fg },
        { label: t('dash.dps_balance'), value: Number(deposit.dps), color: TONES.teal.fg },
        { label: t('dash.fdr_balance'), value: Number(deposit.fdr), color: TONES.amber.fg },
        { label: t('dash.other_balance'), value: Number(deposit.other), color: TONES.slate.fg },
    ].filter((p) => p.value > 0);
    const total = parts.reduce((s, p) => s + p.value, 0);
    const R = 52;
    const C = 2 * Math.PI * R;
    let offset = 0;
    return (
        <div className="flex flex-wrap items-center gap-5">
            <svg viewBox="0 0 140 140" className="h-[140px] w-[140px] shrink-0" role="img" aria-label={t('dash.deposit_mix')}>
                <circle cx="70" cy="70" r={R} fill="none" strokeWidth="20" className="stroke-line dark:stroke-zinc-800" />
                {total > 0 && parts.map((p) => {
                    const frac = p.value / total;
                    const el = (
                        <circle
                            key={p.label}
                            cx="70" cy="70" r={R} fill="none"
                            stroke={p.color} strokeWidth="20"
                            strokeDasharray={`${Math.max(frac * C - 2, 0.5)} ${C}`}
                            strokeDashoffset={-offset * C}
                            transform="rotate(-90 70 70)"
                        >
                            <title>{`${p.label}: ${fmt(p.value)}`}</title>
                        </circle>
                    );
                    offset += frac;
                    return el;
                })}
                <text x="70" y="66" textAnchor="middle" fontSize="11" fill="currentColor" className="text-ink-3 dark:text-zinc-500">{t('total')}</text>
                <text x="70" y="84" textAnchor="middle" fontSize="13" fontWeight="700" fill="currentColor" className="text-ink dark:text-zinc-50">{fmt(total)}</text>
            </svg>
            <ul className="min-w-[180px] flex-1 space-y-2">
                {parts.map((p) => (
                    <li key={p.label} className="flex items-baseline justify-between gap-3 text-[13px]">
                        <span className="flex items-center gap-2 text-ink-2 dark:text-zinc-400">
                            <span className="h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: p.color }} />
                            {p.label}
                        </span>
                        <span className="font-semibold text-ink dark:text-zinc-100">
                            {fmt(p.value)} <span className="font-normal text-ink-3">({total > 0 ? ((p.value / total) * 100).toFixed(1) : '0.0'}%)</span>
                        </span>
                    </li>
                ))}
                {parts.length === 0 && <li className="text-[13px] text-ink-3">—</li>}
            </ul>
        </div>
    );
}

function LoanBars({ loan }) {
    const rows = [
        { label: t('dash.disbursed_amount'), value: Number(loan.disbursed_amount), color: TONES.blue.fg },
        { label: t('loan_outstanding'), value: Number(loan.outstanding), color: TONES.green.fg },
        { label: t('overdue'), value: Number(loan.overdue_amount), color: TONES.rose.fg },
    ];
    const max = Math.max(...rows.map((r) => r.value), 0);
    return (
        <div className="space-y-3.5 pt-1">
            {rows.map((r) => (
                <div key={r.label}>
                    <div className="mb-1 flex items-baseline justify-between gap-3 text-[13px]">
                        <span className="text-ink-2 dark:text-zinc-400">{r.label}</span>
                        <span className="font-semibold text-ink dark:text-zinc-100">{fmt(r.value)}</span>
                    </div>
                    <div className="h-3.5 overflow-hidden rounded bg-surface-2 dark:bg-zinc-800">
                        <div className="h-full rounded" style={{ width: `${max > 0 ? Math.max((r.value / max) * 100, r.value > 0 ? 1.5 : 0) : 0}%`, backgroundColor: r.color }} />
                    </div>
                </div>
            ))}
        </div>
    );
}

function ChartCard({ title, children }) {
    return (
        <section className="overflow-hidden rounded-xl bg-white shadow-[var(--shadow-card)] dark:bg-zinc-900">
            <h3 className="border-b border-line px-4 py-3 text-[15px] font-bold text-brand dark:border-zinc-800 dark:text-zinc-100">{title}</h3>
            <div className="px-4 py-3">{children}</div>
        </section>
    );
}

function SummaryTable({ title, firstCol, rows, renderFirst }) {
    return (
        <section className="overflow-hidden rounded-xl bg-white shadow-[var(--shadow-card)] dark:bg-zinc-900">
            <h3 className="border-b border-line px-4 py-3 text-[15px] font-bold text-brand dark:border-zinc-800 dark:text-zinc-100">{title}</h3>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[560px] text-sm">
                    <thead>
                        <tr className="bg-surface-3 text-left text-[12.5px] uppercase tracking-wide text-brand dark:bg-zinc-800 dark:text-zinc-200">
                            <th className="px-4 py-2 font-bold">{firstCol}</th>
                            <th className="px-3 py-2 text-right font-bold">{t('members')}</th>
                            <th className="px-3 py-2 text-right font-bold">{t('savings_balance')}</th>
                            <th className="px-3 py-2 text-right font-bold">{t('loan_outstanding')}</th>
                            <th className="px-4 py-2 text-right font-bold">{t('dash.month_collection')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((r, i) => (
                            <tr key={i} className="border-t border-line dark:border-zinc-800">
                                <td className="px-4 py-2 text-ink dark:text-zinc-100">{renderFirst(r)}</td>
                                <td className="px-3 py-2 text-right text-ink-2 dark:text-zinc-300">{num(r.members)}</td>
                                <td className="px-3 py-2 text-right text-ink-2 dark:text-zinc-300">{fmt(r.savings)}</td>
                                <td className="px-3 py-2 text-right text-ink-2 dark:text-zinc-300">{fmt(r.outstanding)}</td>
                                <td className="px-4 py-2 text-right font-medium text-ink dark:text-zinc-100">{fmt(r.collection)}</td>
                            </tr>
                        ))}
                        {rows.length === 0 && (
                            <tr className="border-t border-line dark:border-zinc-800">
                                <td colSpan={5} className="px-4 py-4 text-center text-ink-3">—</td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

export default function Dashboard({ asOn, kpis, trend, samityRows, officerRows, branches, selectedBranch }) {
    const m = kpis.members;
    const d = kpis.deposit;
    const l = kpis.loan;
    const c = kpis.collection;

    return (
        <AppLayout>
            {/* Header: title + as-on business date + branch filter */}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-[22px] font-bold leading-tight text-brand dark:text-zinc-50">{t('dashboard')}</h1>
                    <p className="text-[13px] text-ink-2 dark:text-zinc-400">
                        {t('dash.as_on')}: <span className="font-semibold text-ink dark:text-zinc-100">{asOn}</span>
                    </p>
                </div>
                {branches.length > 1 && (
                    <label className="flex items-center gap-2 text-[13px] text-ink-2 dark:text-zinc-400">
                        {t('branch')}
                        <select
                            value={selectedBranch ?? ''}
                            onChange={(e) => {
                                const v = e.target.value;
                                router.get('/', v ? { branch_id: v } : {}, { preserveScroll: true });
                            }}
                            className="min-h-[38px] rounded-lg border border-line bg-white px-2.5 text-sm text-ink outline-none focus:border-brand dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        >
                            <option value="">{t('dash.all_branches')}</option>
                            {branches.map((b) => (
                                <option key={b.id} value={b.id}>{b.code} — {b.name}</option>
                            ))}
                        </select>
                    </label>
                )}
            </div>

            {/* KPI card groups */}
            <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <KpiGroup
                    title={t('members')}
                    icon={Users}
                    tone="blue"
                    headline={{ label: t('total_members'), value: num(m.total) }}
                    rows={[
                        { label: t('dash.active_members'), value: num(m.active) },
                        { label: t('dash.new_members_month'), value: num(m.new_month) },
                        { label: t('dash.closed_members'), value: num(m.closed) },
                        { label: t('samities'), value: num(kpis.samities.active) },
                    ]}
                />
                <KpiGroup
                    title={t('deposit')}
                    icon={PiggyBank}
                    tone="green"
                    headline={{ label: t('dash.total_deposit'), value: fmt(d.total) }}
                    rows={[
                        { label: t('dash.deposit_accounts'), value: num(d.accounts) },
                        { label: t('savings_balance'), value: fmt(d.savings) },
                        { label: t('dash.dps_balance'), value: fmt(d.dps) },
                        { label: t('dash.fdr_balance'), value: fmt(d.fdr) },
                    ]}
                />
                <KpiGroup
                    title={t('loan')}
                    icon={Landmark}
                    tone="violet"
                    headline={{ label: t('loan_outstanding'), value: fmt(l.outstanding) }}
                    rows={[
                        { label: t('dash.loans_disbursed'), value: `${num(l.disbursed_count)} · ${fmt(l.disbursed_amount)}` },
                        { label: t('dash.active_loans'), value: num(l.active) },
                        { label: t('overdue'), value: fmt(l.overdue_amount), alert: Number(l.overdue_amount) > 0 },
                        { label: t('dash.overdue_loans'), value: num(l.overdue_loans), alert: Number(l.overdue_loans) > 0 },
                    ]}
                />
                <KpiGroup
                    title={t('collection')}
                    icon={Banknote}
                    tone="amber"
                    headline={{ label: t('dash.today_collection'), value: fmt(c.today) }}
                    rows={[
                        { label: t('loan_receive'), value: fmt(c.today_loan) },
                        { label: t('deposit_receive'), value: fmt(c.today_deposit) },
                        { label: t('dash.month_collection'), value: fmt(c.month) },
                    ]}
                />
            </div>

            {/* Charts row */}
            <div className="mt-4 grid gap-4 xl:grid-cols-3">
                <div className="xl:col-span-1">
                    <ChartCard title={t('dash.collection_trend')}>
                        <TrendChart trend={trend} />
                    </ChartCard>
                </div>
                <ChartCard title={t('dash.deposit_mix')}>
                    <MixDonut deposit={d} />
                </ChartCard>
                <ChartCard title={t('dash.loan_overview')}>
                    <LoanBars loan={l} />
                </ChartCard>
            </div>

            {/* Summary tables */}
            <div className="mt-4 grid gap-4 xl:grid-cols-2">
                <SummaryTable
                    title={t('dash.samity_wise')}
                    firstCol={t('samity')}
                    rows={samityRows}
                    renderFirst={(r) => <span><span className="font-semibold">{r.code}</span> — {r.name}</span>}
                />
                <SummaryTable
                    title={t('dash.officer_wise')}
                    firstCol={t('field_officer')}
                    rows={officerRows}
                    renderFirst={(r) => (r.officer ? <span className="font-medium">{r.officer}</span> : <span className="text-ink-3">{t('dash.unassigned')}</span>)}
                />
            </div>

            {/* Mega-hub stays on the home page, below the overview */}
            <div className="mt-10 border-t-2 border-brand/40 pt-6">
                <HomeHub />
            </div>
        </AppLayout>
    );
}
