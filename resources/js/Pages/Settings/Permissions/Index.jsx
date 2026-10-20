import { useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';
import { inputCls } from '../../../Components/ui';

/** Read-only permission catalog grouped by module (Step 2). */
export default function PermissionsIndex({ permissionGroups }) {
    const [filter, setFilter] = useState('');
    const groups = permissionGroups
        .map((g) => ({ ...g, permissions: g.permissions.filter((p) => !filter || p.name.includes(filter) || t(`perm.${p.name}`).includes(filter)) }))
        .filter((g) => g.permissions.length > 0);

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('permissions')}</h1>
            <input className={`${inputCls} mb-4 max-w-sm`} placeholder={t('search')} value={filter} onChange={(e) => setFilter(e.target.value)} />
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {groups.map((g) => (
                    <div key={g.module} className="rounded-2xl bg-white p-4 shadow dark:bg-zinc-900">
                        <h2 className="mb-2 font-medium capitalize">{g.module} <span className="text-xs text-zinc-500">({g.permissions.length})</span></h2>
                        <ul className="space-y-1 text-sm">
                            {g.permissions.map((p) => (
                                <li key={p.id} className="flex flex-wrap justify-between gap-2">
                                    <span>{t(`perm.${p.name}`)}</span>
                                    <span className="font-mono text-xs text-zinc-400">{p.name}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
