import { Head, router } from '@inertiajs/react';
import AppLayout from '../../../Layouts/AppLayout';
import { Card, PrimaryButton } from '../../../Components/ui';
import { t } from '../../../lib/i18n';

export default function Index({ branch, currentDate, nextDate }) {
    const close = () => {
        if (window.confirm(t('day_close_confirm'))) {
            router.post('/settings/business-day/close');
        }
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
            </Card>
        </AppLayout>
    );
}
