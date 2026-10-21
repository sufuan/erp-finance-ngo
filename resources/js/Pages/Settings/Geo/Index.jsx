import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Field, PrimaryButton, inputCls } from '../../../Components/ui';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

/**
 * One generic page for all seven geographic masters (Step 4). Desktop
 * shows a table; small screens show cards. Parent selects cascade:
 * choosing a division filters districts, and so on down the chain.
 */
export default function GeoIndex({ entity, label, items, parentFields, filters, entities }) {
    const [editingId, setEditingId] = useState(null);
    const [search, setSearch] = useState(filters.search ?? '');
    const empty = { name: '', ...Object.fromEntries(parentFields.map((f) => [f.key, ''])) };
    const { data, setData, post, put, processing, errors, reset } = useForm(empty);

    useEffect(() => {
        const id = setTimeout(() => {
            router.get(`/settings/geo/${entity}`, { search, per_page: filters.per_page }, { preserveState: true, replace: true });
        }, 350);
        return () => clearTimeout(id);
    }, [search]); // eslint-disable-line react-hooks/exhaustive-deps

    const optionsFor = (field) => {
        if (!field.depends_on) return field.options;
        const parentValue = data[field.depends_on];
        if (!parentValue) return [];
        return field.options.filter((o) => String(o[field.depends_on]) === String(parentValue));
    };

    const startEdit = (item) => {
        setEditingId(item.id);
        const next = { name: item.name };
        parentFields.forEach((f) => { next[f.key] = item[f.key] ?? ''; });
        if (entity === 'villages') next.union_id = item.union_id ?? '';
        setData(next);
    };
    const startCreate = () => { setEditingId(null); reset(); setData(empty); };
    const submit = (e) => {
        e.preventDefault();
        if (editingId) put(`/settings/geo/${entity}/${editingId}`, { preserveScroll: true, onSuccess: () => setEditingId(null) });
        else post(`/settings/geo/${entity}`, { preserveScroll: true, onSuccess: () => reset() });
    };
    const changeParent = (field, value) => {
        // Reset descendants when a parent changes (chain stays valid).
        const next = { ...data, [field.key]: value };
        parentFields.forEach((f) => { if (f.depends_on === field.key) next[f.key] = ''; });
        setData(next);
    };

    const parentNames = (item) =>
        parentFields.map((f) => item[f.label]?.name).filter(Boolean).join(' / ');

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('geography')}</h1>

            <div className="mb-4 flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-800">
                {entities.map((e) => (
                    <Link key={e.slug} href={`/settings/geo/${e.slug}`}
                        className={`min-h-[44px] whitespace-nowrap px-3 py-2 text-sm font-medium ${entity === e.slug ? 'border-b-2 border-emerald-600 text-emerald-700 dark:text-emerald-400' : 'text-zinc-500'}`}>
                        {t(e.label)}
                    </Link>
                ))}
            </div>

            <form onSubmit={submit} className="mb-6 rounded-2xl bg-white p-4 shadow dark:bg-zinc-900">
                <h2 className="mb-3 font-medium">{editingId ? t('edit') : t('create')} — {t(label)}</h2>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label={t('full_name')} required error={errors.name}>
                        <input className={inputCls} value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                    </Field>
                    {parentFields.map((f) => (
                        <Field key={f.key} label={t(f.label)} required={entity !== 'working-areas'} error={errors[f.key]}>
                            <select className={inputCls} value={data[f.key]} onChange={(e) => changeParent(f, e.target.value)} required={entity !== 'working-areas'}>
                                <option value="">—</option>
                                {optionsFor(f).map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                            </select>
                        </Field>
                    ))}
                </div>
                <div className="mt-4 flex gap-2">
                    <PrimaryButton type="submit" disabled={processing}>{t('save')}</PrimaryButton>
                    {editingId && <button type="button" onClick={startCreate} className="min-h-[44px] rounded-lg px-4 py-2 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('cancel')}</button>}
                </div>
            </form>

            <div className="mb-3 flex flex-wrap items-center gap-3">
                <input className={`${inputCls} max-w-xs`} placeholder={t('search')} value={search} onChange={(e) => setSearch(e.target.value)} />
                <label className="flex items-center gap-2 text-sm">
                    {t('show')}
                    <select className={inputCls} value={filters.per_page}
                        onChange={(e) => router.get(`/settings/geo/${entity}`, { search, per_page: e.target.value }, { preserveState: true })}>
                        {[10, 25, 50, 100].map((n) => <option key={n} value={n}>{n}</option>)}
                    </select>
                    {t('entries')}
                </label>
                <span className="text-sm text-zinc-500">{items.total}</span>
            </div>

            {/* Desktop table */}
            <div className="hidden overflow-x-auto rounded-2xl bg-white shadow md:block dark:bg-zinc-900">
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="border-b border-zinc-200 dark:border-zinc-800">
                            <th className="px-4 py-3">{t('full_name')}</th>
                            <th className="px-4 py-3">{t('parent')}</th>
                            <th className="px-4 py-3">{t('actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {items.data.map((item) => (
                            <tr key={item.id} className="border-b border-zinc-100 dark:border-zinc-800">
                                <td className="px-4 py-3 font-medium">{item.name}</td>
                                <td className="px-4 py-3">{parentNames(item) || '—'}</td>
                                <td className="px-4 py-3">
                                    <div className="flex gap-2">
                                        <button type="button" onClick={() => startEdit(item)} className="rounded-lg px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('edit')}</button>
                                        <button type="button" onClick={() => router.delete(`/settings/geo/${entity}/${item.id}`, { preserveScroll: true })} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('delete')}</button>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {/* Mobile cards */}
            <div className="space-y-2 md:hidden">
                {items.data.map((item) => (
                    <div key={item.id} className="rounded-2xl bg-white p-4 shadow dark:bg-zinc-900">
                        <p className="font-medium">{item.name}</p>
                        <p className="text-sm text-zinc-500">{parentNames(item)}</p>
                        <div className="mt-2 flex gap-2">
                            <button type="button" onClick={() => startEdit(item)} className="min-h-[44px] rounded-lg px-3 py-2 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('edit')}</button>
                            <button type="button" onClick={() => router.delete(`/settings/geo/${entity}/${item.id}`, { preserveScroll: true })} className="min-h-[44px] rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('delete')}</button>
                        </div>
                    </div>
                ))}
            </div>

            <div className="mt-4 flex items-center gap-2">
                {items.prev_page_url && <Link href={items.prev_page_url} className="min-h-[44px] rounded-lg bg-white px-4 py-2 text-sm shadow dark:bg-zinc-900">{t('previous')}</Link>}
                <span className="text-sm text-zinc-500">{items.current_page} / {items.last_page}</span>
                {items.next_page_url && <Link href={items.next_page_url} className="min-h-[44px] rounded-lg bg-white px-4 py-2 text-sm shadow dark:bg-zinc-900">{t('next')}</Link>}
            </div>
        </AppLayout>
    );
}
