import { router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Field, PrimaryButton, inputCls } from '../../../Components/ui';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

const empty = { name: '', label: '', description: '', permissions: [] };

/** Roles admin with the grouped permission matrix (module = a section, plan §4.1). */
export default function RolesIndex({ roles, permissionGroups }) {
    const [editingId, setEditingId] = useState(null);
    const [filter, setFilter] = useState('');
    const { data, setData, post, put, processing, errors, reset } = useForm(empty);

    const startEdit = (r) => {
        setEditingId(r.id);
        setData({ name: r.name, label: r.label ?? '', description: r.description ?? '', permissions: r.permissions });
    };
    const startCreate = () => { setEditingId(null); reset(); setData(empty); };
    const submit = (e) => {
        e.preventDefault();
        if (editingId) put(`/settings/roles/${editingId}`, { preserveScroll: true, onSuccess: () => setEditingId(null) });
        else post('/settings/roles', { preserveScroll: true, onSuccess: () => reset() });
    };

    const togglePerm = (name) =>
        setData('permissions', data.permissions.includes(name) ? data.permissions.filter((p) => p !== name) : [...data.permissions, name]);
    const toggleModule = (perms, on) =>
        setData('permissions', on ? [...new Set([...data.permissions, ...perms])] : data.permissions.filter((p) => !perms.includes(p)));

    const groups = useMemo(() => permissionGroups
        .map((g) => ({ ...g, permissions: g.permissions.filter((p) => !filter || p.name.includes(filter) || t(`perm.${p.name}`).includes(filter)) }))
        .filter((g) => g.permissions.length > 0), [permissionGroups, filter]);

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('roles')}</h1>

            <div className="mb-6 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {roles.map((r) => (
                    <div key={r.id} className="rounded-2xl bg-white p-4 shadow dark:bg-zinc-900">
                        <div className="flex items-start justify-between gap-2">
                            <div>
                                <p className="font-medium">{r.label ?? r.name}</p>
                                <p className="text-xs text-zinc-500">{r.name}{r.is_system ? ` • ${t('system_role')}` : ''}</p>
                            </div>
                            <div className="flex gap-1">
                                <button type="button" onClick={() => startEdit(r)} className="rounded-lg px-2 py-1 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('edit')}</button>
                                {!r.is_system && (
                                    <button type="button" onClick={() => router.delete(`/settings/roles/${r.id}`, { preserveScroll: true })} className="rounded-lg px-2 py-1 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('delete')}</button>
                                )}
                            </div>
                        </div>
                        <p className="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{r.description}</p>
                        <p className="mt-2 text-xs text-zinc-500">{r.permissions.length} {t('permissions_count')} • {r.users_count} {t('users_count')}</p>
                    </div>
                ))}
            </div>

            <form onSubmit={submit} className="rounded-2xl bg-white p-4 shadow dark:bg-zinc-900">
                <h2 className="mb-3 font-medium">{editingId ? t('edit') : t('new_role')}</h2>
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field label={t('role_name')} required error={errors.name}><input className={inputCls} value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. data-entry" required /></Field>
                    <Field label={t('full_name')} error={errors.label}><input className={inputCls} value={data.label} onChange={(e) => setData('label', e.target.value)} /></Field>
                    <Field label={t('description')} error={errors.description}><input className={inputCls} value={data.description} onChange={(e) => setData('description', e.target.value)} /></Field>
                </div>

                <div className="mt-5">
                    <div className="mb-3 flex flex-wrap items-center gap-3">
                        <h3 className="font-medium">{t('permission_matrix')}</h3>
                        <input className={`${inputCls} max-w-xs`} placeholder={t('search')} value={filter} onChange={(e) => setFilter(e.target.value)} />
                        <span className="text-sm text-zinc-500">{data.permissions.length} {t('permissions_count')}</span>
                    </div>
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {groups.map((g) => {
                            const names = g.permissions.map((p) => p.name);
                            const allOn = names.every((n) => data.permissions.includes(n));
                            return (
                                <fieldset key={g.module} className="rounded-xl border border-zinc-200 p-3 dark:border-zinc-800">
                                    <legend className="px-1 text-sm font-semibold capitalize">{g.module}</legend>
                                    <label className="mb-2 flex min-h-[44px] items-center gap-2 text-sm font-medium">
                                        <input type="checkbox" className="h-5 w-5" checked={allOn} onChange={(e) => toggleModule(names, e.target.checked)} />
                                        {t('select_all')}
                                    </label>
                                    <div className="space-y-1">
                                        {g.permissions.map((p) => (
                                            <label key={p.id} className="flex min-h-[36px] items-center gap-2 text-sm">
                                                <input type="checkbox" className="h-5 w-5" checked={data.permissions.includes(p.name)} onChange={() => togglePerm(p.name)} />
                                                {t(`perm.${p.name}`)}
                                            </label>
                                        ))}
                                    </div>
                                </fieldset>
                            );
                        })}
                    </div>
                </div>

                <div className="mt-4 flex gap-2">
                    <PrimaryButton type="submit" disabled={processing}>{t('save')}</PrimaryButton>
                    {editingId && <button type="button" onClick={startCreate} className="min-h-[44px] rounded-lg px-4 py-2 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('cancel')}</button>}
                </div>
            </form>
        </AppLayout>
    );
}
