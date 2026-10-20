import { Link } from '@inertiajs/react';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

export default function LoginHistory({ targetUser, history }) {
    return (
        <AppLayout>
            <h1 className="mb-1 text-xl font-semibold">{t('login_history')}</h1>
            <p className="mb-4 text-sm text-zinc-500">{targetUser.name} ({targetUser.username})</p>
            <div className="overflow-x-auto rounded-2xl bg-white shadow dark:bg-zinc-900">
                <table className="w-full min-w-[640px] text-left text-sm">
                    <thead>
                        <tr className="border-b border-zinc-200 dark:border-zinc-800">
                            <th className="px-4 py-3">{t('date')}</th>
                            <th className="px-4 py-3">{t('ip_address')}</th>
                            <th className="px-4 py-3">{t('status')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {history.map((h) => (
                            <tr key={h.id} className="border-b border-zinc-100 dark:border-zinc-800">
                                <td className="px-4 py-3">{h.created_at}</td>
                                <td className="px-4 py-3">{h.ip ?? '—'}</td>
                                <td className="px-4 py-3">{h.success ? t('success') : t('failed')}</td>
                            </tr>
                        ))}
                        {history.length === 0 && (
                            <tr><td className="px-4 py-3" colSpan={3}>{t('no_data_found')}</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
            <Link href="/settings/users" className="mt-4 inline-block rounded-lg px-3 py-2 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">← {t('users')}</Link>
        </AppLayout>
    );
}
