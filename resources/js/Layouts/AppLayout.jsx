import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    BookOpen, Briefcase, Building2, CalendarDays, Globe2, HandCoins, Home, IdCard, Landmark, Loader2, LogOut, Megaphone,
    Menu, Moon, PiggyBank, Scale, ScrollText, Settings, Sun, Users,
} from 'lucide-react';
import Toasts from '../Components/Toast';
import { TONES } from '../lib/hubs';
import { getLocale, initI18n, t } from '../lib/i18n';

/**
 * Application shell (redesign to the approved prototype): blue top header
 * with org branding, business date, locale + night toggles and the user
 * block; NOTICE ticker under the header; grouped icon sidebar (no search
 * box in the sidebar) that becomes a drawer on mobile; logged-in user card
 * at the sidebar bottom. Active item = soft green highlight.
 */
export default function AppLayout({ children, branches: branchesProp = undefined, activeBranchId: activeBranchIdProp = undefined }) {
    const { props, url } = usePage();
    const branches = branchesProp !== undefined ? branchesProp : props.branches ?? null;
    const activeBranchId =
        activeBranchIdProp !== undefined ? activeBranchIdProp : props.activeBranch?.id ?? null;
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [dark, setDark] = useState(
        () => typeof document !== 'undefined' && document.documentElement.classList.contains('dark'),
    );

    // Backstop sync of the i18n store during render. The primary sync is
    // lib/withI18n, which wraps every page in app.jsx and runs before the
    // page body — syncing only here was too late for page content (the
    // page body executes before this layout's body), which made content
    // lag one language switch behind the sidebar.
    initI18n({
        locale: props.locale,
        translations: props.translations,
        translations_version: props.translations_version,
    });

    const toggleDark = () => {
        const next = !dark;
        setDark(next);
        document.documentElement.classList.toggle('dark', next);
        localStorage.setItem('tpda_theme', next ? 'dark' : 'light');
    };

    const [switchingLocale, setSwitchingLocale] = useState(null);
    const switchLocale = (locale) => {
        if (locale !== getLocale() && !switchingLocale) {
            router.post(`/locale/${locale}`, {}, {
                preserveScroll: true,
                onStart: () => setSwitchingLocale(locale),
                onFinish: () => setSwitchingLocale(null),
            });
        }
    };

    const [switchingBranch, setSwitchingBranch] = useState(false);
    const switchBranch = (branchId) => {
        if (switchingBranch) return;
        router.post('/branch/switch', { branch_id: branchId }, {
            preserveScroll: true,
            onStart: () => setSwitchingBranch(true),
            onFinish: () => setSwitchingBranch(false),
        });
    };

    const can = props.auth?.can ?? {};
    const item = (key, href, labelKey, icon, tone, gate, activeWhen) =>
        (!gate || can[gate] ? [{ key, href, labelKey, icon, tone, activeWhen: activeWhen ?? [href] }] : []);
    const groups = [
        {
            labelKey: 'nav_main',
            items: [...item('home', '/', 'home', Home, 'blue', null, ['/'])],
        },
        {
            labelKey: 'nav_field_work',
            items: [
                ...item('members', '/hub/member', 'members', Users, 'blue', 'members', ['/hub/member', '/members']),
                ...item('collection', '/hub/collection', 'collection', HandCoins, 'green', 'collection', ['/hub/collection', '/collection']),
                ...item('samities', '/settings/samities', 'samities', Users, 'violet', 'settings', ['/settings/samities']),
            ],
        },
        {
            labelKey: 'nav_money',
            items: [
                ...item('deposits', '/hub/deposit', 'deposits', PiggyBank, 'teal', 'deposits', ['/hub/deposit', '/deposits']),
                ...item('loans', '/hub/loan', 'loans', Landmark, 'amber', 'loans', ['/hub/loan', '/loans']),
                ...item('accounts', '/hub/accounts', 'module_accounts', BookOpen, 'cyan', 'accounts', ['/hub/accounts', '/accounts']),
            ],
        },
        {
            labelKey: 'nav_office',
            items: [
                ...item('employees', '/settings/employees', 'employees', IdCard, 'blue', 'employees', ['/settings/employees']),
                ...item('hrm', '/hub/hrm', 'hrm', Briefcase, 'violet', 'hrm', ['/hub/hrm', '/hrm']),
                ...item('branches', '/settings/branches', 'branches', Building2, 'teal', null, ['/settings/branches']),
            ],
        },
        {
            labelKey: 'nav_reports',
            items: [
                ...item('reports-deposits', '/reports/deposits', 'deposit_reports', ScrollText, 'violet', 'reports', ['/reports/deposits']),
                ...item('reports-loans', '/reports/loans', 'loan_reports', ScrollText, 'amber', 'reports', ['/reports/loans']),
                ...item('reports-llp', '/reports/llp', 'llp', Scale, 'orange', 'reports', ['/reports/llp']),
            ],
        },
        {
            labelKey: 'nav_settings',
            items: [
                ...item('users', '/settings/users', 'users_roles', Users, 'violet', 'users', ['/settings/users']),
                ...item('general', '/settings/general', 'general_settings', Settings, 'blue', 'settings', ['/settings/general']),
                ...item('geo', '/settings/geo/villages', 'geography', Globe2, 'cyan', 'geo', ['/settings/geo']),
                ...item('holidays', '/settings/holidays', 'holidays', Sun, 'orange', 'settings', ['/settings/holidays']),
                ...item('notices', '/settings/notices', 'notices', Megaphone, 'rose', 'settings', ['/settings/notices']),
                ...item('business-day', '/settings/business-day', 'closing_business_day', CalendarDays, 'amber', null, ['/settings/business-day']),
            ],
        },
    ].filter((g) => g.items.length > 0);

    const isActive = (it) =>
        it.activeWhen.some((p) => (p === '/' ? url === '/' : url.startsWith(p)));

    const user = props.auth?.user;
    const initials = (user?.name ?? '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase())
        .join('');

    const sidebarBody = (onNavigate) => (
        <div className="flex h-full flex-col bg-white dark:bg-zinc-900">
            <nav className="flex-1 overflow-y-auto px-2.5 py-2">
                {groups.map((g) => (
                    <div key={g.labelKey}>
                        <p className="px-2 pb-1 pt-3.5 text-[11px] font-bold uppercase tracking-[1.4px] text-ink-3">
                            {t(g.labelKey)}
                        </p>
                        <div className="space-y-0.5">
                            {g.items.map((it) => {
                                const Icon = it.icon;
                                const active = isActive(it);
                                return (
                                    <Link
                                        key={it.key}
                                        href={it.href}
                                        onClick={onNavigate}
                                        className={`flex min-h-[40px] items-center gap-[11px] rounded-[9px] px-2.5 py-[8.5px] text-[13.5px] font-medium transition ${
                                            active
                                                ? 'bg-active-bg font-semibold text-accent-ink dark:bg-emerald-900/30 dark:text-emerald-300'
                                                : 'text-ink-2 hover:bg-surface-2 dark:text-zinc-300 dark:hover:bg-zinc-800'
                                        }`}
                                    >
                                        <Icon className="h-[18px] w-[18px] shrink-0" style={{ color: (TONES[it.tone] ?? TONES.blue).fg }} strokeWidth={1.9} />
                                        <span className="leading-snug">{t(it.labelKey)}</span>
                                    </Link>
                                );
                            })}
                        </div>
                    </div>
                ))}
            </nav>
            {user && (
                <div className="border-t border-line p-3 dark:border-zinc-800">
                    <div className="mb-2 flex items-center gap-2.5 px-1">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand/10 text-[13px] font-bold text-brand dark:bg-zinc-800 dark:text-zinc-100">
                            {initials}
                        </span>
                        <span className="min-w-0">
                            <span className="block truncate text-[13px] font-semibold text-ink dark:text-zinc-100">{user.name}</span>
                            <span className="block truncate text-[11.5px] text-ink-3">
                                {user.username}
                                {props.activeBranch ? ` • ${props.activeBranch.name}` : ''}
                            </span>
                        </span>
                    </div>
                    <button
                        type="button"
                        onClick={() => router.post('/logout')}
                        className="flex min-h-[40px] w-full items-center gap-2 rounded-[9px] px-2.5 py-2 text-left text-[13.5px] font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20"
                    >
                        <LogOut className="h-[18px] w-[18px]" /> {t('logout')}
                    </button>
                </div>
            )}
        </div>
    );

    return (
        <div className="flex min-h-screen flex-col bg-paper text-ink dark:bg-zinc-950 dark:text-zinc-100">
            {/* Top header */}
            <header className="sticky top-0 z-40 bg-[linear-gradient(90deg,var(--color-brand),var(--color-brand-deep))] text-white shadow-[var(--shadow-card)] dark:bg-[linear-gradient(90deg,var(--color-brand-night),var(--color-brand-night-deep))]">
                <div className="flex min-h-[58px] flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2 sm:px-4">
                    <div className="flex min-w-0 items-center gap-2.5">
                        <button
                            type="button"
                            className="rounded-lg p-2 hover:bg-white/10 lg:hidden"
                            onClick={() => setDrawerOpen(true)}
                            aria-label={t('menu')}
                        >
                            <Menu className="h-6 w-6" />
                        </button>
                        <span className="hidden h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/15 sm:flex">
                            <Landmark className="h-5 w-5" />
                        </span>
                        <span className="min-w-0 leading-tight">
                            <span className="block truncate text-[17px] font-bold">{props.appName}</span>
                            {props.orgName && (
                                <span className="block truncate text-[11.5px] text-white/75">{props.orgName}</span>
                            )}
                        </span>
                    </div>

                    <div className="ml-auto flex flex-wrap items-center gap-2 sm:gap-3">
                        {props.businessDate && (
                            <span className="text-sm">
                                <span className="hidden text-white/80 md:inline">{t('business_day')}: </span>
                                <span className="font-bold text-tang">{props.businessDate}</span>
                            </span>
                        )}
                        {branches && branches.length > 0 && (
                            <select
                                className="min-h-[40px] max-w-[180px] rounded-lg border border-white/30 bg-white/10 px-2 py-1.5 text-sm text-white disabled:opacity-60 [&>option]:text-ink"
                                value={activeBranchId ?? ''}
                                disabled={switchingBranch}
                                onChange={(e) => switchBranch(e.target.value)}
                                aria-label={t('select_branch')}
                            >
                                {branches.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.code} — {b.name}
                                    </option>
                                ))}
                            </select>
                        )}

                        {/* Locale toggle: EN | বাংলা */}
                        <div className="flex overflow-hidden rounded-lg border border-white/40 text-sm">
                            {[
                                ['en', 'EN'],
                                ['bn', 'বাংলা'],
                            ].map(([loc, label]) => (
                                <button
                                    key={loc}
                                    type="button"
                                    disabled={!!switchingLocale}
                                    onClick={() => switchLocale(loc)}
                                    className={`flex min-h-[40px] items-center gap-1.5 px-3 py-1.5 disabled:opacity-70 ${props.locale === loc ? 'bg-white font-semibold text-brand' : 'text-white hover:bg-white/10'}`}
                                >
                                    {switchingLocale === loc && (
                                        <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                                    )}
                                    {label}
                                </button>
                            ))}
                        </div>

                        {/* Night mode */}
                        <button
                            type="button"
                            onClick={toggleDark}
                            className="rounded-lg p-2 hover:bg-white/10"
                            aria-label={t('night_mode')}
                            title={t('night_mode')}
                        >
                            {dark ? <Sun className="h-5 w-5" /> : <Moon className="h-5 w-5" />}
                        </button>

                        {user && (
                            <span className="hidden items-center gap-2 md:flex">
                                <span className="flex h-8 w-8 items-center justify-center rounded-full bg-white/20 text-xs font-bold">
                                    {initials}
                                </span>
                                <span className="leading-tight">
                                    <span className="block text-[13px] font-medium">{user.name}</span>
                                    {props.activeBranch && (
                                        <span className="block text-[11px] text-white/75">{props.activeBranch.name}</span>
                                    )}
                                </span>
                                <button
                                    type="button"
                                    onClick={() => router.post('/logout')}
                                    className="rounded-lg p-2 hover:bg-white/10"
                                    aria-label={t('logout')}
                                    title={t('logout')}
                                >
                                    <LogOut className="h-5 w-5" />
                                </button>
                            </span>
                        )}
                    </div>
                </div>
            </header>

            {/* NOTICE ticker */}
            {props.notice && (
                <div className="flex h-[38px] items-center gap-3 border-b border-line bg-white px-3 sm:px-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <span className="shrink-0 rounded-full bg-[#e05252] px-2.5 py-1 text-[11px] font-bold tracking-wide text-white">
                        {t('notice_label')}
                    </span>
                    <div className="min-w-0 flex-1 overflow-hidden">
                        <div className="flex w-max animate-ticker hover:[animation-play-state:paused]">
                            <span className="whitespace-nowrap pr-16 text-[14.5px] font-semibold text-accent-ink dark:text-emerald-400">
                                {props.notice.text}
                            </span>
                            <span className="whitespace-nowrap pr-16 text-[14.5px] font-semibold text-accent-ink dark:text-emerald-400" aria-hidden="true">
                                {props.notice.text}
                            </span>
                        </div>
                    </div>
                </div>
            )}

            <div className="flex flex-1 items-start">
                {/* Desktop sidebar */}
                <aside className="sticky top-[58px] hidden h-[calc(100vh-58px)] w-[238px] shrink-0 border-r border-line lg:block dark:border-zinc-800">
                    {sidebarBody(undefined)}
                </aside>

                {/* Mobile drawer */}
                {drawerOpen && (
                    <div className="fixed inset-0 z-50 lg:hidden">
                        <div
                            className="absolute inset-0 bg-black/50"
                            onClick={() => setDrawerOpen(false)}
                            aria-hidden="true"
                        />
                        <aside className="absolute inset-y-0 left-0 w-[260px] shadow-xl">
                            {sidebarBody(() => setDrawerOpen(false))}
                        </aside>
                    </div>
                )}

                {/* Content */}
                <div className="min-w-0 flex-1">
                    <main className="mx-auto w-full max-w-[1400px] px-3 py-4 sm:px-5 lg:px-7 lg:py-6">
                        {children}
                    </main>
                    <footer className="px-4 pb-6 text-center text-xs text-ink-3 dark:text-zinc-500">
                        {t('powered_by')}
                    </footer>
                </div>
            </div>
            <Toasts />
        </div>
    );
}
