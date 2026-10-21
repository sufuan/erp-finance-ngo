import { router, usePage } from '@inertiajs/react';
import { getLocale, initI18n, t } from '../lib/i18n';

/** Centered card shell for login / TOTP / password pages (no sidebar). */
export default function AuthLayout({ title, children }) {
    const { props } = usePage();

    // Render-time i18n sync — see AppLayout (useEffect sync lagged one switch).
    initI18n({
        locale: props.locale,
        translations: props.translations,
        translations_version: props.translations_version,
    });

    const switchLocale = (locale) => {
        if (locale !== getLocale()) router.post(`/locale/${locale}`, {}, { preserveScroll: true });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-zinc-100 px-3 py-8 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100">
            <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow dark:bg-zinc-900">
                <div className="mb-4 flex items-center justify-between">
                    <span className="text-lg font-semibold text-emerald-700 dark:text-emerald-400">{props.appName}</span>
                    <div className="flex overflow-hidden rounded-lg border border-zinc-300 text-sm dark:border-zinc-700">
                        <button type="button" onClick={() => switchLocale('bn')} className={`min-h-[44px] px-3 py-2 ${props.locale === 'bn' ? 'bg-emerald-600 text-white' : ''}`}>বাংলা</button>
                        <button type="button" onClick={() => switchLocale('en')} className={`min-h-[44px] px-3 py-2 ${props.locale === 'en' ? 'bg-emerald-600 text-white' : ''}`}>English</button>
                    </div>
                </div>
                {title && <h1 className="mb-4 text-xl font-semibold">{title}</h1>}
                {props.flash?.error && (
                    <div className="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200">{props.flash.error}</div>
                )}
                {children}
                <p className="mt-6 text-center text-xs text-zinc-500 dark:text-zinc-400">{t('powered_by')}</p>
            </div>
        </div>
    );
}
