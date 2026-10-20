import { Head } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';
import { t } from '../lib/i18n';

export default function Welcome() {
    return (
        <AppLayout>
            <Head title={t('dashboard')} />
            <div className="rounded-2xl bg-white p-6 shadow-sm dark:bg-zinc-900">
                <h1 className="text-2xl font-semibold">{t('welcome')}</h1>
                <p className="bn-text mt-2 text-zinc-600 dark:text-zinc-300">
                    {/* Bengali conjunct smoke string (G10): must render with no broken glyphs */}
                    ক্ষ জ্ঞ ১২৩ — {t('dashboard')}
                </p>
            </div>
        </AppLayout>
    );
}
