import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ActionButton, Field, PrimaryButton, inputCls } from '../../../Components/ui';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

const empty = { name: '', username: '', email: '', employee_id: '', locale: 'bn', password: '', roles: [], branch_ids: [], branch_children: [], samity_ids: '' };

export default function UsersIndex({ users, roles, branches }) {
    const { props } = usePage();
    const [editingId, setEditingId] = useState(null);
    const { data, setData, post, put, processing, errors, reset } = useForm(empty);

    const startEdit = (u) => {
        setEditingId(u.id);
        setData({
            name: u.name, username: u.username, email: u.email ?? '', employee_id: u.employee_id ?? '',
            locale: u.locale, password: '',
            roles: u.roles,
            branch_ids: u.branches.map((b) => b.id),
            branch_children: u.branches.filter((b) => b.include_children).map((b) => b.id),
            samity_ids: (u.samity_ids ?? []).join(', '),
        });
    };
    const startCreate = () => { setEditingId(null); reset(); setData(empty); };
    const submit = (e) => {
        e.preventDefault();
        if (editingId) put(`/settings/users/${editingId}`, { preserveScroll: true, onSuccess: () => setEditingId(null) });
        else post('/settings/users', { preserveScroll: true, onSuccess: () => reset() });
    };
    const toggleIn = (field, value) =>
        setData(field, data[field].includes(value) ? data[field].filter((v) => v !== value) : [...data[field], value]);

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('users')}</h1>

            {props.flash?.temp_password && (
                <div className="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">
                    {t('temporary_password_for')} <b>{props.flash.temp_username}</b>: <span className="font-mono font-semibold">{props.flash.temp_password}</span>
                </div>
            )}

            <form onSubmit={submit} className="mb-6 rounded-2xl bg-white p-4 shadow dark:bg-zinc-900">
                <h2 className="mb-3 font-medium">{editingId ? t('edit') : t('new_user')}</h2>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('full_name')} required error={errors.name}><input className={inputCls} value={data.name} onChange={(e) => setData('name', e.target.value)} required /></Field>
                    <Field label={t('username')} required error={errors.username}><input className={inputCls} value={data.username} onChange={(e) => setData('username', e.target.value)} required /></Field>
                    <Field label={t('email_address')} error={errors.email}><input type="email" className={inputCls} value={data.email} onChange={(e) => setData('email', e.target.value)} /></Field>
                    <Field label={t('employees_short')} error={errors.employee_id}><input type="number" className={inputCls} value={data.employee_id} onChange={(e) => setData('employee_id', e.target.value)} /></Field>
                    {!editingId && (
                        <Field label={t('password')} required error={errors.password}><input type="password" className={inputCls} value={data.password} onChange={(e) => setData('password', e.target.value)} required /></Field>
                    )}
                    <Field label={t('language')} error={errors.locale}>
                        <select className={inputCls} value={data.locale} onChange={(e) => setData('locale', e.target.value)}>
                            <option value="bn">বাংলা</option><option value="en">English</option>
                        </select>
                    </Field>
                </div>

                <div className="mt-4 grid gap-4 lg:grid-cols-2">
                    <fieldset>
                        <legend className="mb-2 text-sm font-medium">{t('assign_roles')}</legend>
                        <div className="space-y-1">
                            {roles.map((r) => (
                                <label key={r.id} className="flex min-h-[44px] items-center gap-2 text-sm">
                                    <input type="checkbox" className="h-5 w-5" checked={data.roles.includes(r.name)} onChange={() => toggleIn('roles', r.name)} />
                                    {r.label ?? r.name} <span className="text-zinc-400">({r.name})</span>
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    <fieldset>
                        <legend className="mb-2 text-sm font-medium">{t('assign_branches')}</legend>
                        <div className="space-y-1">
                            {branches.map((b) => (
                                <div key={b.id} className="flex min-h-[44px] flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                                    <label className="flex items-center gap-2">
                                        <input type="checkbox" className="h-5 w-5" checked={data.branch_ids.includes(b.id)} onChange={() => toggleIn('branch_ids', b.id)} />
                                        {b.code} — {b.name}
                                    </label>
                                    {data.branch_ids.includes(b.id) && (
                                        <label className="flex items-center gap-2 text-zinc-500">
                                            <input type="checkbox" className="h-5 w-5" checked={data.branch_children.includes(b.id)} onChange={() => toggleIn('branch_children', b.id)} />
                                            {t('include_children')}
                                        </label>
                                    )}
                                </div>
                            ))}
                        </div>
                    </fieldset>
                </div>

                <div className="mt-4">
                    <Field label={t('assign_samities')} error={errors.samity_ids}>
                        <input className={inputCls} value={data.samity_ids} onChange={(e) => setData('samity_ids', e.target.value)} placeholder="1, 2, 3" />
                    </Field>
                    <p className="mt-1 text-xs text-zinc-500">{t('samity_ids_hint')}</p>
                </div>

                <div className="mt-4 flex gap-2">
                    <PrimaryButton type="submit" loading={processing}>{t('save')}</PrimaryButton>
                    {editingId && <button type="button" onClick={startCreate} className="min-h-[44px] rounded-lg px-4 py-2 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('cancel')}</button>}
                </div>
            </form>

            <div className="overflow-x-auto rounded-2xl bg-white shadow dark:bg-zinc-900">
                <table className="w-full min-w-[900px] text-left text-sm">
                    <thead>
                        <tr className="border-b border-zinc-200 dark:border-zinc-800">
                            <th className="px-4 py-3">{t('full_name')}</th>
                            <th className="px-4 py-3">{t('username')}</th>
                            <th className="px-4 py-3">{t('roles')}</th>
                            <th className="px-4 py-3">{t('status')}</th>
                            <th className="px-4 py-3">{t('last_login')}</th>
                            <th className="px-4 py-3">{t('actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {users.map((u) => (
                            <tr key={u.id} className="border-b border-zinc-100 dark:border-zinc-800">
                                <td className="px-4 py-3 font-medium">{u.name}</td>
                                <td className="px-4 py-3">{u.username}</td>
                                <td className="px-4 py-3">{u.roles.join(', ') || '—'}</td>
                                <td className="px-4 py-3">{u.is_active ? t('active') : t('inactive')}</td>
                                <td className="px-4 py-3">{u.last_login_at ?? '—'}</td>
                                <td className="px-4 py-3">
                                    <div className="flex flex-wrap gap-2">
                                        <button type="button" onClick={() => startEdit(u)} className="rounded-lg px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('edit')}</button>
                                        <ActionButton fire={(cb) => router.post(`/settings/users/${u.id}/reset-password`, {}, { preserveScroll: true, ...cb })} className="rounded-lg px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('reset_password')}</ActionButton>
                                        <ActionButton fire={(cb) => router.post(`/settings/users/${u.id}/toggle-active`, {}, { preserveScroll: true, ...cb })} className="rounded-lg px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">{u.is_active ? t('deactivate') : t('activate')}</ActionButton>
                                        <Link href={`/settings/users/${u.id}/login-history`} className="rounded-lg px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('login_history')}</Link>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
