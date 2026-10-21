import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, inputCls } from '../../../Components/ui';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

/**
 * Employee / director register (Step 6) — one parameterized page for both
 * registers: personal + bank sections, photo upload, full-column tables
 * on desktop and stacked cards at 360px.
 */
export default function RegistersIndex({ entity, label, items, fields, search }) {
    const [editing, setEditing] = useState(null);
    const [query, setQuery] = useState(search ?? '');
    const empty = Object.fromEntries(fields.map((f) => [f.key, '']));
    // Status starts at Active (the register form's "—" option would
    // otherwise submit an empty status, which the backend rejects).
    if ('status' in empty) empty.status = 'Active';
    if ('director_status' in empty) empty.director_status = 'Active';
    const { data, setData, post, processing, errors, reset } = useForm({ ...empty, photo: null });

    const base = `/settings/${entity}`;
    const rows = items.data ?? items;

    const startCreate = () => { setEditing(null); reset(); setData({ ...empty, photo: null }); };
    const startEdit = (item) => {
        setEditing(item);
        const next = { photo: null };
        fields.forEach((f) => {
            let v = item[f.key] ?? '';
            if (f.type === 'date' && typeof v === 'string') v = v.slice(0, 10);
            next[f.key] = v;
        });
        setData(next);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, forceFormData: true, onSuccess: () => startCreate() };
        if (editing) router.post(`${base}/${editing.id}`, { ...data, _method: 'put' }, opts);
        else post(base, opts);
    };
    const doSearch = (e) => { e.preventDefault(); router.get(base, { search: query }, { preserveState: true }); };

    const renderField = (f) => (
        <Field key={f.key} label={`${t(f.label)}${f.required ? ' *' : ''}`} error={errors[f.key]}>
            {f.type === 'select' ? (
                <select className={inputCls} value={data[f.key]} onChange={(e) => setData(f.key, e.target.value)} required={f.required}>
                    <option value="">—</option>
                    {(f.options ?? []).map((o) => (
                        <option key={o.id} value={o.id}>{o.label_key ? t(o.label_key) : o.name}</option>
                    ))}
                </select>
            ) : f.type === 'textarea' ? (
                <textarea className={inputCls} rows={2} value={data[f.key]} onChange={(e) => setData(f.key, e.target.value)} />
            ) : (
                <input type={f.type === 'date' ? 'date' : f.type === 'email' ? 'email' : 'text'}
                    className={inputCls} value={data[f.key]} onChange={(e) => setData(f.key, e.target.value)} required={f.required} />
            )}
        </Field>
    );

    const sections = ['personal', 'bank'];
    const cellValue = (item, key) => {
        if (key === 'branch_id') return item.branch ? `${item.branch.code} — ${item.branch.name}` : '—';
        if (key === 'department_id') return item.department?.name ?? '—';
        if (key === 'designation_id') return item.designation?.name ?? '—';
        const v = item[key];
        return v === null || v === undefined || v === '' ? '—' : String(v).slice(0, 60);
    };

    return (
        <AppLayout>
            <div className="mb-4 flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-800">
                {['employees', 'directors'].map((slug) => (
                    <Link key={slug} href={`/settings/${slug}`}
                        className={`min-h-[44px] whitespace-nowrap px-3 py-2 text-sm font-medium ${entity === slug ? 'border-b-2 border-emerald-600 text-emerald-700 dark:text-emerald-400' : 'text-zinc-500'}`}>
                        {t(slug === 'employees' ? 'employees' : 'directors')}
                    </Link>
                ))}
            </div>

            <form onSubmit={doSearch} className="mb-4 flex gap-2">
                <input className={inputCls} placeholder={t('search')} value={query} onChange={(e) => setQuery(e.target.value)} />
                <PrimaryButton type="submit">{t('search')}</PrimaryButton>
            </form>

            <Card className="mb-6">
                <h2 className="mb-3 font-medium">{editing ? t('edit') : t('create')} — {t(label)}</h2>
                <form onSubmit={submit}>
                    {sections.map((section) => (
                        <fieldset key={section} className="mb-4">
                            <legend className="mb-2 text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                                {t(section === 'personal' ? 'personal_information' : 'bank_details')}
                            </legend>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {fields.filter((f) => f.section === section).map(renderField)}
                            </div>
                        </fieldset>
                    ))}
                    <Field label={t('photo')} error={errors.photo}>
                        <input type="file" accept="image/jpeg,image/png" className={inputCls}
                            onChange={(e) => setData('photo', e.target.files?.[0] ?? null)} />
                    </Field>
                    {editing?.photo_url && (
                        <img src={editing.photo_url} alt="" className="mt-2 h-20 w-20 rounded-lg object-cover" />
                    )}
                    <div className="mt-4 flex gap-2">
                        <PrimaryButton type="submit" disabled={processing}>{t('save')}</PrimaryButton>
                        {editing && (
                            <button type="button" onClick={startCreate} className="min-h-[44px] rounded-lg px-4 py-2 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('cancel')}</button>
                        )}
                    </div>
                </form>
            </Card>

            {/* Desktop: full table incl. photo */}
            <div className="hidden overflow-x-auto rounded-2xl bg-white shadow md:block dark:bg-zinc-900">
                <table className="w-full min-w-[1100px] text-left text-sm">
                    <thead>
                        <tr className="border-b border-zinc-200 dark:border-zinc-800">
                            <th className="px-3 py-3">{t('photo')}</th>
                            {fields.map((f) => <th key={f.key} className="whitespace-nowrap px-3 py-3">{t(f.label)}</th>)}
                            <th className="px-3 py-3">{t('actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((item) => (
                            <tr key={item.id} className="border-b border-zinc-100 dark:border-zinc-800">
                                <td className="px-3 py-2">
                                    {item.photo_url
                                        ? <img src={item.photo_url} alt="" className="h-10 w-10 rounded-full object-cover" />
                                        : <span className="text-zinc-400">—</span>}
                                </td>
                                {fields.map((f) => <td key={f.key} className="whitespace-nowrap px-3 py-2">{cellValue(item, f.key)}</td>)}
                                <td className="px-3 py-2">
                                    <button type="button" onClick={() => startEdit(item)} className="rounded-lg px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('edit')}</button>
                                </td>
                            </tr>
                        ))}
                        {rows.length === 0 && <tr><td className="px-3 py-3" colSpan={fields.length + 2}>{t('no_data_found')}</td></tr>}
                    </tbody>
                </table>
            </div>

            {/* Mobile: stacked cards */}
            <div className="space-y-3 md:hidden">
                {rows.map((item) => (
                    <Card key={item.id}>
                        <div className="flex items-center gap-3">
                            {item.photo_url && <img src={item.photo_url} alt="" className="h-12 w-12 rounded-full object-cover" />}
                            <div>
                                <div className="font-medium">{item.name}</div>
                                <div className="text-sm text-zinc-500">{item.employee_code ?? ''} {item.mobile}</div>
                            </div>
                        </div>
                        <dl className="mt-3 grid grid-cols-2 gap-2 text-sm">
                            {fields.slice(0, 8).map((f) => (
                                <div key={f.key}>
                                    <dt className="text-zinc-500">{t(f.label)}</dt>
                                    <dd>{cellValue(item, f.key)}</dd>
                                </div>
                            ))}
                        </dl>
                        <button type="button" onClick={() => startEdit(item)} className="mt-3 min-h-[44px] rounded-lg px-3 py-2 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('edit')}</button>
                    </Card>
                ))}
                {rows.length === 0 && <Card>{t('no_data_found')}</Card>}
            </div>

            {items.last_page > 1 && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {(items.links ?? []).map((link, i) => (
                        <button key={i} type="button" disabled={!link.url}
                            onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                            className="min-h-[44px] rounded-lg border border-zinc-300 px-3 py-2 text-sm disabled:opacity-40 dark:border-zinc-700"
                            dangerouslySetInnerHTML={{ __html: link.label }} />
                    ))}
                </div>
            )}
        </AppLayout>
    );
}
