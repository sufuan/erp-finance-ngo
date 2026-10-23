import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import GeoCascade from '../../../Components/GeoCascade';
import {ActionButton, Field, PrimaryButton, inputCls } from '../../../Components/ui';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

const CHAIN_KEYS = [
    'division_id', 'division_name', 'district_id', 'district_name',
    'upazila_id', 'upazila_name', 'union_id', 'union_name',
];
const emptyChain = Object.fromEntries(CHAIN_KEYS.map((k) => [k, '']));

/**
 * Manual geography masters (reworked): only Villages and Working Areas
 * live here. Division → District → Upazila → Union are picked through
 * GeoCascade (bd-geo-info package data) and stored as plain values.
 * A village's chain is complete (it hangs under a union); a working
 * area's chain and village are optional.
 */
export default function GeoIndex({ entity, label, items, parentFields, filters, entities }) {
    const isVillage = entity === 'villages';
    const [editingId, setEditingId] = useState(null);
    const [search, setSearch] = useState(filters.search ?? '');
    const empty = { name: '', ...emptyChain, village_id: '' };
    const { data, setData, post, put, processing, errors, reset } = useForm(empty);
    const villageField = parentFields.find((f) => f.key === 'village_id');

    useEffect(() => {
        const id = setTimeout(() => {
            router.get(`/settings/geo/${entity}`, { search, per_page: filters.per_page }, { preserveState: true, replace: true });
        }, 350);
        return () => clearTimeout(id);
    }, [search]); // eslint-disable-line react-hooks/exhaustive-deps

    const startEdit = (item) => {
        setEditingId(item.id);
        const next = { name: item.name, village_id: item.village_id ?? '' };
        CHAIN_KEYS.forEach((k) => { next[k] = item[k] ?? ''; });
        setData(next);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };
    const startCreate = () => { setEditingId(null); reset(); setData(empty); };
    const submit = (e) => {
        e.preventDefault();
        if (editingId) put(`/settings/geo/${entity}/${editingId}`, { preserveScroll: true, onSuccess: () => setEditingId(null) });
        else post(`/settings/geo/${entity}`, { preserveScroll: true, onSuccess: () => reset() });
    };

    // Location summary for a row: village name (areas) + the chain names.
    const parentNames = (item) => {
        const parts = [];
        if (item.village?.name) parts.push(item.village.name);
        ['union_name', 'upazila_name', 'district_name', 'division_name'].forEach((k) => {
            if (item[k]) parts.push(item[k]);
        });
        return parts.join(' / ');
    };

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
                    <GeoCascade
                        value={data}
                        onChange={(next) => setData({ ...data, ...next })}
                        required={isVillage}
                        errors={errors}
                    />
                    {villageField && (
                        <Field label={t('village')} error={errors.village_id}>
                            <select className={inputCls} value={data.village_id} onChange={(e) => setData('village_id', e.target.value)}>
                                <option value="">—</option>
                                {villageField.options.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                            </select>
                        </Field>
                    )}
                </div>
                <div className="mt-4 flex gap-2">
                    <PrimaryButton type="submit" loading={processing}>{t('save')}</PrimaryButton>
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
                                        <ActionButton fire={(cb) => router.delete(`/settings/geo/${entity}/${item.id}`, { preserveScroll: true, ...cb })} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('delete')}</ActionButton>
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
                            <ActionButton fire={(cb) => router.delete(`/settings/geo/${entity}/${item.id}`, { preserveScroll: true, ...cb })} className="min-h-[44px] rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('delete')}</ActionButton>
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
