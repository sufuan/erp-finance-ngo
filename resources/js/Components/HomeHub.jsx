import { Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { ArrowRight, Search } from 'lucide-react';
import { HubTileGrid } from './hub';
import { moduleLinks, visibleModules } from '../lib/hubs';
import { t } from '../lib/i18n';

/**
 * The mega-hub block (module search + one tile section per module).
 * Shared by the Home page and the Dashboard (Step 24), which renders
 * the management overview above this same hub.
 */
export default function HomeHub() {
    const { props } = usePage();
    const can = props.auth?.can ?? {};
    const [query, setQuery] = useState('');

    const sections = useMemo(() => {
        const q = query.trim().toLowerCase();
        return visibleModules(can)
            .map((mod) => {
                const links = moduleLinks(mod);
                const matched = q
                    ? links.filter((l) => t(l.labelKey).toLowerCase().includes(q))
                    : links;
                return { mod, links: matched };
            })
            .filter((s) => s.links.length > 0);
    }, [can, query]);

    return (
        <>
            {/* Module search */}
            <div className="mx-auto w-full max-w-[760px]">
                <label className="flex min-h-[52px] items-center gap-3 rounded-xl border-[1.5px] border-line bg-white px-4 shadow-[var(--shadow-card)] focus-within:border-brand dark:border-zinc-700 dark:bg-zinc-900">
                    <Search className="h-5 w-5 shrink-0 text-ink-3" />
                    <input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder={t('search_modules_placeholder')}
                        className="min-w-0 flex-1 bg-transparent text-[15px] text-ink outline-none placeholder:text-ink-3 dark:text-zinc-100"
                    />
                    <span className="shrink-0 text-[15px] font-bold text-brand">{t('search')}</span>
                </label>
            </div>

            {sections.length === 0 && (
                <p className="mt-10 text-center text-sm text-ink-2 dark:text-zinc-400">{t('no_matching_option')}</p>
            )}

            {sections.map(({ mod, links }) => (
                <section key={mod.key} className="mt-[26px]">
                    <div className="mb-3 flex items-end justify-between gap-3 border-b-2 border-brand/70 pb-1.5">
                        <h2 className="text-[19px] font-bold leading-tight text-brand dark:text-zinc-100">{t(mod.titleKey)}</h2>
                        <Link
                            href={`/hub/${mod.key}`}
                            className="flex shrink-0 items-center gap-1 text-[13px] font-medium text-brand hover:underline dark:text-zinc-300"
                        >
                            {t('show_all_options')} <ArrowRight className="h-3.5 w-3.5" />
                        </Link>
                    </div>
                    <HubTileGrid links={links} />
                </section>
            ))}
        </>
    );
}
