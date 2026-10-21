import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { TONES } from '../lib/hubs';
import { t } from '../lib/i18n';

/** One colorful module tile (prototype: pastel tile, rich icon, formal). */
export function HubTile({ link }) {
    const tone = TONES[link.tone] ?? TONES.blue;
    const Icon = link.icon;
    return (
        <Link
            href={link.href}
            className="group flex min-h-[104px] flex-col items-center justify-center gap-2 rounded-xl p-3 text-center transition hover:bg-white hover:shadow-[var(--shadow-card)] hover:outline hover:outline-1 hover:outline-line dark:hover:bg-zinc-900"
            style={{ backgroundColor: tone.bg }}
        >
            <Icon className="h-9 w-9" style={{ color: tone.fg }} strokeWidth={1.6} />
            <span className="text-[13.5px] font-medium leading-snug text-ink dark:text-zinc-800">{t(link.labelKey)}</span>
        </Link>
    );
}

export function HubTileGrid({ links }) {
    return (
        <div className="grid grid-cols-[repeat(auto-fill,minmax(128px,1fr))] gap-3">
            {links.map((l) => (
                <HubTile key={l.href} link={l} />
            ))}
        </div>
    );
}

/** White subsection cards with icon-led link rows (hub page pattern). */
export function HubCards({ cards }) {
    return (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {cards.map((card) => (
                <section key={card.titleKey} className="overflow-hidden rounded-xl bg-white shadow-[var(--shadow-card)] dark:bg-zinc-900">
                    <h3 className="bg-surface-3 px-4 py-2.5 text-[15px] font-bold text-brand dark:bg-zinc-800 dark:text-zinc-100">
                        {t(card.titleKey)}
                    </h3>
                    <ul className="py-1.5">
                        {card.links.map((l) => {
                            const tone = TONES[l.tone] ?? TONES.blue;
                            const Icon = l.icon;
                            return (
                                <li key={l.href}>
                                    <Link
                                        href={l.href}
                                        className="flex min-h-[40px] items-center gap-2.5 px-4 py-1.5 text-sm text-ink-2 transition hover:bg-surface-2 hover:text-brand dark:text-zinc-300 dark:hover:bg-zinc-800"
                                    >
                                        <span className="h-1.5 w-1.5 shrink-0 rounded-full" style={{ backgroundColor: tone.fg }} />
                                        <Icon className="h-4.5 w-4.5 shrink-0" style={{ color: tone.fg }} strokeWidth={1.8} />
                                        <span className="leading-snug">{t(l.labelKey)}</span>
                                        <ChevronRight className="ml-auto h-4 w-4 shrink-0 text-ink-3" />
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </section>
            ))}
        </div>
    );
}
