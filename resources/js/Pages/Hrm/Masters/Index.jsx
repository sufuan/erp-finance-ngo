import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Field, PrimaryButton, inputCls } from '../../../Components/ui';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

/**
 * Generic HR master page (Step 5): departments, designations, employee
 * types, shifts — driven by the field config from the controller so every
 * column of the plan's tables is editable.
 */
export default function MastersIndex({ entity, label, items, fields, entities }) {
    const [editingId, setEditingId] = useState(null);
    const empty = Object.fromEntries(fields.map((f) => [f.key, f.type === 'checkbox' ? true : '']));
    const { data, setData, post, put, processing, errors, reset } = useForm(empty);

    const startEdit = (item) => {
        setEditingId(item.id);
        const next = {};
        fields.forEach((f) => {
            let v = item[f.key] ?? '';
            if (f.type === 'time' && typeof v === 'string') v = v.slice(0, 5);
            next[f.key] = f.type === 'checkbox' ? !!v : v;
        });
        setData(next);
    };
    const startCreate = () => { setEditingId(null); reset(); setData(empty); };
    const submit = (e) => {
        e.preventDefault();
        if (editingId) put(`/hrm/${entity}/${editingId}`, { preserveScroll: true, onSuccess: () => setEditingId(null) });
        else post(`/hrm/${entity}`, { preserveScroll: true, onSuccess: () => reset() });
    };

    const renderField = (f) => {
        if (f.type === 'checkbox') {
            return (
                <label className="flex min-h-[44px] items-center gap-2 text-sm">
                    <input type="checkbox" className="h-5 w-5" checked={!!data[f.key]} onChange={(e) => setData(f.key, e.target.checked)} /> {t(f.label)}
                </label>
            );
        }
        return (
            <Field label={t(f.label)} error={errors[f.key]}>
                {f.type === 'select' ? (
                    <select className={inputCls} value={data[f.key]} onChange={(e) => setData(f.key, e.target.value)} required={f.required}>
                        <option value="">—</option>
                        {f.options.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </select>
                ) : (
                    <input type={f.type === 'time' ? 'time' : f.type === 'number' ? 'number' : 'text'}
                        step={f.type === 'number' ? 'any' : undefined}
                        className={inputCls} value={data[f.key]} onChange={(e) => setData(f.key, e.target.value)} required={f.required} />
                )}
            </Field>
        );
    };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('hrm')}</h1>

            <div className="mb-4 flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-800">
                {entities.map((e) => (
                    <Link key={e.slug} href={`/hrm/${e.slug}`}
                        className={`min-h-[44px] whitespace-nowrap px-3 py-2 text-sm font-medium ${entity === e.slug ? 'border-b-2 border-emerald-600 text-emerald-700 dark:text-emerald-400' : 'text-zinc-500'}`}>
                        {t(e.label)}
                    </Link>
                ))}
            </div>

            <form onSubmit={submit} className="mb-6 rounded-2xl bg-white p-4 shadow dark:bg-zinc-900">
                <h2 className="mb-3 font-medium">{editingId ? t('edit') : t('create')} — {t(label)}</h2>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {fields.map((f) => <div key={f.key}>{renderField(f)}</div>)}
                </div>
                <div className="mt-4 flex gap-2">
                    <PrimaryButton type="submit" disabled={processing}>{t('save')}</PrimaryButton>
                    {editingId && <button type="button" onClick={startCreate} className="min-h-[44px] rounded-lg px-4 py-2 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('cancel')}</button>}
                </div>
            </form>

            <div className="overflow-x-auto rounded-2xl bg-white shadow dark:bg-zinc-900">
                <table className="w-full min-w-[720px] text-left text-sm">
                    <thead>
                        <tr className="border-b border-zinc-200 dark:border-zinc-800">
                            {fields.map((f) => <th key={f.key} className="px-4 py-3">{t(f.label)}</th>)}
                            <th className="px-4 py-3">{t('actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {items.map((item) => (
                            <tr key={item.id} className="border-b border-zinc-100 dark:border-zinc-800">
                                {fields.map((f) => (
                                    <td key={f.key} className="px-4 py-3">
                                        {f.type === 'checkbox' ? (item[f.key] ? t('active') : t('inactive'))
                                            : f.type === 'select' ? (item.branch ? `${item.branch.code} — ${item.branch.name}` : item[f.key] ?? '—')
                                            : (item[f.key] ?? '—')}
                                    </td>
                                ))}
                                <td className="px-4 py-3">
                                    <div className="flex gap-2">
                                        <button type="button" onClick={() => startEdit(item)} className="rounded-lg px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('edit')}</button>
                                        <button type="button" onClick={() => router.delete(`/hrm/${entity}/${item.id}`, { preserveScroll: true })} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('delete')}</button>
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {items.length === 0 && <tr><td className="px-4 py-3" colSpan={fields.length + 1}>{t('no_data_found')}</td></tr>}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
