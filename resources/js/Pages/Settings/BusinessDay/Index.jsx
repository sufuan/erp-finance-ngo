import { Head, router } from '@inertiajs/react';
import AppLayout from '../../../Layouts/AppLayout';
import { Card, PrimaryButton } from '../../../Components/ui';
import { t } from '../../../lib/i18n';

export default function Index({ branch, currentDate, nextDate, autoClose }) {
    const close = () => {
        if (window.confirm(t('day_close_confirm'))) {
            router.post('/settings/business-day/close');
        }
    };

    const toggleAutoClose = (e) => {
        router.post('/settings/business-day/auto-close', { enabled: e.target.checked }, { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title={t('closing_business_day')} />
            <Card className="mx-auto max-w-xl">
                <h1 className="mb-4 text-xl font-semibold">{t('closing_business_day')}</h1>
                {branch && (
                    <p className="mb-4 text-sm text-zinc-500 dark:text-zinc-400">
                        {t('branch')}: {branch.code} — {branch.name}
                    </p>
                )}
                <dl className="space-y-3 text-sm">
                    <div className="flex items-center justify-between rounded-lg bg-zinc-50 px-4 py-3 dark:bg-zinc-800">
                        <dt className="font-medium">{t('current_date')}</dt>
                        <dd className="text-base font-semibold">{currentDate}</dd>
                    </div>
                    <div className="flex items-center justify-between rounded-lg bg-zinc-50 px-4 py-3 dark:bg-zinc-800">
                        <dt className="font-medium">{t('next_date')}</dt>
                        <dd className="text-base font-semibold">{nextDate}</dd>
                    </div>
                </dl>
                <div className="mt-6">
                    <PrimaryButton type="button" onClick={close}>{t('start_closing')}</PrimaryButton>
                </div>
                <div className="mt-6 border-t border-zinc-200 pt-5 dark:border-zinc-700">
                    <label className="flex cursor-pointer items-center gap-3">
                        <span className="relative inline-flex shrink-0">
                            <input
                                type="checkbox"
                                className="peer sr-only"
                                checked={!!autoClose}
                                onChange={toggleAutoClose}
                            />
                            <span className="h-6 w-11 rounded-full bg-zinc-300 transition-colors peer-checked:bg-emerald-600 dark:bg-zinc-700 dark:peer-checked:bg-emerald-600" />
                            <span className="pointer-events-none absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5" />
                        </span>
                        <span className="text-sm font-medium">{t('auto_closing')}</span>
                    </label>
                    <p className="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{t('auto_closing_hint')}</p>
                </div>
            </Card>
        </AppLayout>
    );
}
