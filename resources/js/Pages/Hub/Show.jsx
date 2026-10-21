import { usePage } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { HubCards, HubTileGrid } from '../../Components/hub';
import { TONES, moduleFor } from '../../lib/hubs';
import { t } from '../../lib/i18n';

/**
 * Module hub page (redesign): title + top tiles row + white subsection
 * cards linking to the module's real pages.
 */
export default function HubShow({ module }) {
    const { props } = usePage();
    const can = props.auth?.can ?? {};
    const mod = moduleFor(can, module);
    if (!mod) return <AppLayout>{null}</AppLayout>;

    const tone = TONES[mod.tone] ?? TONES.blue;
    const Icon = mod.icon;

    return (
        <AppLayout>
            <div className="mb-5 flex items-center gap-3">
                <span className="flex h-11 w-11 items-center justify-center rounded-xl" style={{ backgroundColor: tone.bg }}>
                    <Icon className="h-6 w-6" style={{ color: tone.fg }} strokeWidth={1.7} />
                </span>
                <h1 className="border-b-2 border-brand/70 pb-1 text-[19px] font-bold text-brand dark:text-zinc-100">
                    {t(mod.titleKey)}
                </h1>
            </div>

            {mod.tiles.length > 0 && (
                <div className="mb-6">
                    <HubTileGrid links={mod.tiles} />
                </div>
            )}

            <HubCards cards={mod.cards} />
        </AppLayout>
    );
}
