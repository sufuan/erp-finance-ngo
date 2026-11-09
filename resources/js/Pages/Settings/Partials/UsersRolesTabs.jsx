import { Link, usePage } from '@inertiajs/react';
import { t } from '../../../lib/i18n';

/**
 * Users & Roles share ONE sidebar home ("Users & Roles"); this tab bar tops
 * both pages so the pair is managed from a single point. Each tab renders
 * only when the viewer holds its manage permission.
 */
export default function UsersRolesTabs({ active }) {
    const { props } = usePage();
    const can = props.auth?.can ?? {};
    const tabs = [
        can.users && { key: 'users', href: '/settings/users', label: t('users') },
        can.roles && { key: 'roles', href: '/settings/roles', label: t('roles') },
    ].filter(Boolean);

    return (
        <nav aria-label={t('users_roles')} className="mb-4 flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-800">
            {tabs.map((tab) => (
                <Link
                    key={tab.key}
                    href={tab.href}
                    aria-current={active === tab.key ? 'page' : undefined}
                    className={`-mb-px inline-flex min-h-[44px] items-center whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium ${active === tab.key
                        ? 'border-emerald-600 text-emerald-700 dark:text-emerald-400'
                        : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'}`}
                >
                    {tab.label}
                </Link>
            ))}
        </nav>
    );
}
