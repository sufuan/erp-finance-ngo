import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { getLocale, initI18n, t } from '../lib/i18n';

/**
 * Application shell — responsive sidebar (desktop) → drawer (mobile),
 * topbar with branch switcher slot, locale toggle (বাংলা | English)
 * and night-mode toggle. Module nav links are added by later steps.
 */
export default function AppLayout({ children, branches: branchesProp = undefined, activeBranchId: activeBranchIdProp = undefined }) {
    const { props } = usePage();
    const branches = branchesProp !== undefined ? branchesProp : props.branches ?? null;
    const activeBranchId =
        activeBranchIdProp !== undefined ? activeBranchIdProp : props.activeBranch?.id ?? null;
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [dark, setDark] = useState(
        () => typeof document !== 'undefined' && document.documentElement.classList.contains('dark'),
    );

    // Keep the i18n store in sync with the latest shared props.
    useEffect(() => {
        initI18n({
            locale: props.locale,
            translations: props.translations,
            translations_version: props.translations_version,
        });
    }, [props.locale, props.translations_version]); // eslint-disable-line react-hooks/exhaustive-deps

    const toggleDark = () => {
        const next = !dark;
        setDark(next);
        document.documentElement.classList.toggle('dark', next);
        localStorage.setItem('tpda_theme', next ? 'dark' : 'light');
    };

    const switchLocale = (locale) => {
        if (locale !== getLocale()) {
            router.post(`/locale/${locale}`, {}, { preserveScroll: true });
        }
    };

    const switchBranch = (branchId) => {
        router.post('/branch/switch', { branch_id: branchId }, { preserveScroll: true });
    };

    const can = props.auth?.can ?? {};
    const nav = [
        { key: 'dashboard', href: '/', label: t('dashboard') },
        { key: 'branches', href: '/settings/branches', label: t('branches') },
        { key: 'business-day', href: '/settings/business-day', label: t('closing_business_day') },
        ...(can.users ? [{ key: 'users', href: '/settings/users', label: t('users') }] : []),
        ...(can.roles ? [{ key: 'roles', href: '/settings/roles', label: t('roles') }] : []),
        ...(can.permissions ? [{ key: 'permissions', href: '/settings/permissions', label: t('permissions') }] : []),
        ...(can.settings ? [{ key: 'general', href: '/settings/general', label: t('general_settings') }] : []),
        ...(can.geo ? [{ key: 'geo', href: '/settings/geo/countries', label: t('geography') }] : []),
        ...(can.hrm ? [{ key: 'hrm', href: '/hrm/departments', label: t('hrm') }] : []),
        ...(can.employees ? [{ key: 'employees', href: '/settings/employees', label: t('employees') }] : []),
        ...(can.employees ? [{ key: 'directors', href: '/settings/directors', label: t('directors') }] : []),
        ...(can.members ? [{ key: 'members', href: '/members', label: t('members') }] : []),
        ...(can.deposits ? [{ key: 'deposits', href: '/deposits', label: t('deposits') }] : []),
        ...(can.settings ? [{ key: 'samities', href: '/settings/samities', label: t('samities') }] : []),
        ...(can.settings ? [{ key: 'member_migrations', href: '/settings/member-migrations', label: t('member_migrations') }] : []),
        ...(can.settings ? [{ key: 'holidays', href: '/settings/holidays', label: t('holidays') }] : []),
        ...(can.settings ? [{ key: 'notices', href: '/settings/notices', label: t('notices') }] : []),
    ];

    const sidebar = (
        <div className="flex h-full flex-col bg-white dark:bg-zinc-900">
            <div className="flex h-16 items-center gap-2 border-b border-zinc-200 px-4 dark:border-zinc-800">
                <span className="text-lg font-semibold text-emerald-700 dark:text-emerald-400">
                    {props.appName}
                </span>
            </div>
            <nav className="flex-1 space-y-1 overflow-y-auto p-3">
                {nav.map((item) => (
                    <Link
                        key={item.key}
                        href={item.href}
                        className="block rounded-lg px-3 py-2.5 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        onClick={() => setDrawerOpen(false)}
                    >
                        {item.label}
                    </Link>
                ))}
            </nav>
            <div className="border-t border-zinc-200 p-3 dark:border-zinc-800">
                {props.auth?.user && (
                    <button
                        type="button"
                        onClick={() => router.post('/logout')}
                        className="mb-2 block min-h-[44px] w-full rounded-lg px-3 py-2.5 text-left text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20"
                    >
                        {t('logout')} ({props.auth.user.username})
                    </button>
                )}
                <div className="text-xs text-zinc-500 dark:text-zinc-400">{t('powered_by')}</div>
            </div>
        </div>
    );

    return (
        <div className="min-h-screen bg-zinc-100 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100">
            {/* Desktop sidebar */}
            <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 border-r border-zinc-200 lg:block dark:border-zinc-800">
                {sidebar}
            </aside>

            {/* Mobile drawer */}
            {drawerOpen && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div
                        className="absolute inset-0 bg-black/50"
                        onClick={() => setDrawerOpen(false)}
                        aria-hidden="true"
                    />
                    <aside className="absolute inset-y-0 left-0 w-64 shadow-xl">{sidebar}</aside>
                </div>
            )}

            <div className="lg:pl-64">
                {/* Topbar */}
                <header className="sticky top-0 z-20 flex h-16 items-center gap-2 border-b border-zinc-200 bg-white px-3 sm:gap-3 sm:px-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <button
                        type="button"
                        className="rounded-lg p-2 hover:bg-zinc-100 lg:hidden dark:hover:bg-zinc-800"
                        onClick={() => setDrawerOpen(true)}
                        aria-label={t('menu')}
                    >
                        <svg className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                            <path strokeLinecap="round" d="M4 7h16M4 12h16M4 17h16" />
                        </svg>
                    </button>

                    <span className="truncate text-base font-semibold lg:hidden">{props.appName}</span>

                    <div className="ml-auto flex items-center gap-2 sm:gap-3">
                        {props.businessDate && (
                            <span className="hidden text-sm text-zinc-500 md:inline dark:text-zinc-400">
                                {t('business_day')}: {props.businessDate}
                            </span>
                        )}
                        {branches && branches.length > 0 && (
                            <label className="flex items-center gap-2 text-sm">
                                <span className="hidden text-zinc-500 md:inline dark:text-zinc-400">
                                    {t('select_branch')}
                                </span>
                                <select
                                    className="min-h-[44px] rounded-lg border border-zinc-300 bg-white px-2 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                                    value={activeBranchId ?? ''}
                                    onChange={(e) => switchBranch(e.target.value)}
                                >
                                    {branches.map((b) => (
                                        <option key={b.id} value={b.id}>
                                            {b.code} — {b.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}

                        {/* Locale toggle: বাংলা | English */}
                        <div className="flex overflow-hidden rounded-lg border border-zinc-300 text-sm dark:border-zinc-700">
                            <button
                                type="button"
                                onClick={() => switchLocale('bn')}
                                className={`min-h-[44px] px-3 py-2 ${props.locale === 'bn' ? 'bg-emerald-600 text-white' : 'bg-white hover:bg-zinc-100 dark:bg-zinc-800 dark:hover:bg-zinc-700'}`}
                            >
                                বাংলা
                            </button>
                            <button
                                type="button"
                                onClick={() => switchLocale('en')}
                                className={`min-h-[44px] px-3 py-2 ${props.locale === 'en' ? 'bg-emerald-600 text-white' : 'bg-white hover:bg-zinc-100 dark:bg-zinc-800 dark:hover:bg-zinc-700'}`}
                            >
                                English
                            </button>
                        </div>

                        {/* Night mode */}
                        <button
                            type="button"
                            onClick={toggleDark}
                            className="min-h-[44px] rounded-lg p-2 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                            aria-label={t('night_mode')}
                            title={t('night_mode')}
                        >
                            {dark ? (
                                <svg className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
                                </svg>
                            ) : (
                                <svg className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                                </svg>
                            )}
                        </button>

                        {props.auth?.user && (
                            <span className="hidden text-sm font-medium sm:inline">
                                {props.auth.user.name}
                            </span>
                        )}
                    </div>
                </header>

                {props.notice && (
                    <div className={`px-4 py-2 text-center text-sm font-medium ${
                        props.notice.color === 'Red' ? 'bg-red-600 text-white'
                        : props.notice.color === 'Green' ? 'bg-emerald-600 text-white'
                        : props.notice.color === 'Yellow' ? 'bg-yellow-300 text-zinc-900'
                        : 'bg-zinc-900 text-white'}`}>
                        {props.notice.text}
                    </div>
                )}

                <main className="mx-auto w-full max-w-7xl p-3 sm:p-4 lg:p-6">
                    {props.flash?.success && (
                        <div className="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200">
                            {props.flash.success}
                        </div>
                    )}
                    {props.flash?.error && (
                        <div className="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200">
                            {props.flash.error}
                        </div>
                    )}
                    {children}
                </main>

                <footer className="px-4 pb-6 text-center text-xs text-zinc-500 dark:text-zinc-400">
                    {t('powered_by')}
                </footer>
            </div>
        </div>
    );
}
