import { Link, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { Card, Field, PrimaryButton, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

const COLUMNS = [
    ['photo_url', 'photo'], ['member_no', 'member_no'], ['name', 'full_name'], ['samity', 'samity'],
    ['father_name', 'father_name'], ['mother_name', 'mother_name'], ['blood_group', 'blood_group'],
    ['gender', 'gender'], ['religion', 'religion'], ['marital_status', 'marital_status'],
    ['national_id', 'national_id'], ['present_address', 'present_address'], ['permanent_address', 'permanent_address'],
    ['mobile', 'mobile'], ['email', 'email'], ['open_date', 'open_date'], ['birth_date', 'date_of_birth'],
    ['username', 'username'], ['status', 'status'],
];

/** Member list (Step 8): filters, 20-column table, expandable rows, actions. */
export default function MemberIndex({ members, filters, options }) {
    const [f, setF] = useState({
        samity_id: filters.samity_id ?? '', search: filters.search ?? '', phone: filters.phone ?? '',
        member_no: filters.member_no ?? '', nid: filters.nid ?? '', status: filters.status ?? 'All',
        gender: filters.gender ?? '', open_from: filters.open_from ?? '', open_to: filters.open_to ?? '',
    });
    const [searching, setSearching] = useState(false);
    const [showAdvance, setShowAdvance] = useState(false);
    const [expanded, setExpanded] = useState(null);
    const rows = members.data ?? [];

    const apply = (e) => { e.preventDefault(); router.get('/members', f, { preserveState: true, onStart: () => setSearching(true), onFinish: () => setSearching(false) }); };
    const set = (k, v) => setF((prev) => ({ ...prev, [k]: v }));

    const withGeo = (fn) => {
        if (!navigator.geolocation) { fn({}); return; }
        navigator.geolocation.getCurrentPosition(
            (pos) => fn({ latitude: pos.coords.latitude.toFixed(7), longitude: pos.coords.longitude.toFixed(7) }),
            () => fn({}),
            { timeout: 5000 },
        );
    };
    const checkIn = (id) => withGeo((geo) => router.post(`/members/${id}/check-in`, geo, { preserveScroll: true }));
    const updateLocation = (id) => withGeo((geo) => router.post(`/members/${id}/location`, geo, { preserveScroll: true }));

    const cell = (m, key) => {
        if (key === 'photo_url') return m.photo_url ? <img src={m.photo_url} alt="" className="h-10 w-10 rounded-full object-cover" /> : '—';
        if (key === 'samity') return m.samity?.name ?? '—';
        const v = m[key];
        return v === null || v === undefined || v === '' ? '—' : String(v);
    };

    const actions = (m) => (
        <div className="flex flex-wrap gap-1">
            <Link href={`/members/${m.id}`} className="rounded-lg px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">360</Link>
            <Link href={`/members/${m.id}/edit`} className="rounded-lg px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('edit')}</Link>
            <Link href={`/members/${m.id}`} className="rounded-lg px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('documents')}</Link>
            <Link href={`/members/${m.id}/admission-form`} className="rounded-lg px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('admission_form')}</Link>
            <Link href={`/members/${m.id}`} className="rounded-lg px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('add_visiting_info')}</Link>
            <button type="button" onClick={() => checkIn(m.id)} className="rounded-lg px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('check_in')}</button>
            <button type="button" onClick={() => updateLocation(m.id)} className="rounded-lg px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('update_location')}</button>
        </div>
    );

    return (
        <AppLayout>
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <h1 className="text-xl font-semibold">{t('member_list')}</h1>
                <Link href="/members/admission" className="ml-auto min-h-[44px] rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">{t('member_admission')}</Link>
                <Link href="/members/transfer" className="min-h-[44px] rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700">{t('member_transfer')}</Link>
                <Link href="/members/closing" className="min-h-[44px] rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700">{t('member_closing')}</Link>
            </div>

            <Card className="mb-4">
                <form onSubmit={apply} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Field label={t('samity')}>
                        <select className={inputCls} value={f.samity_id} onChange={(e) => set('samity_id', e.target.value)}>
                            <option value="">—</option>
                            {options.samities.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('full_name')}>
                        <input className={inputCls} value={f.search} onChange={(e) => set('search', e.target.value)} />
                    </Field>
                    <Field label={t('mobile')}>
                        <input className={inputCls} value={f.phone} onChange={(e) => set('phone', e.target.value)} />
                    </Field>
                    <Field label={t('status')}>
                        <select className={inputCls} value={f.status} onChange={(e) => set('status', e.target.value)}>
                            {['All', 'Active', 'Inactive', 'Closed'].map((s) => <option key={s} value={s}>{s === 'All' ? t('all') : t(s.toLowerCase())}</option>)}
                        </select>
                    </Field>
                    {showAdvance && (
                        <>
                            <Field label={t('member_no')}>
                                <input className={inputCls} value={f.member_no} onChange={(e) => set('member_no', e.target.value)} />
                            </Field>
                            <Field label={t('national_id')}>
                                <input className={inputCls} value={f.nid} onChange={(e) => set('nid', e.target.value)} />
                            </Field>
                            <Field label={t('gender')}>
                                <select className={inputCls} value={f.gender} onChange={(e) => set('gender', e.target.value)}>
                                    <option value="">—</option>
                                    {options.genders.map((g) => <option key={g.id} value={g.id}>{t(g.label_key)}</option>)}
                                </select>
                            </Field>
                            <Field label={t('open_date')}>
                                <div className="flex gap-2">
                                    <input type="date" className={inputCls} value={f.open_from} onChange={(e) => set('open_from', e.target.value)} />
                                    <input type="date" className={inputCls} value={f.open_to} onChange={(e) => set('open_to', e.target.value)} />
                                </div>
                            </Field>
                        </>
                    )}
                    <div className="flex items-end gap-2">
                        <PrimaryButton type="submit" loading={searching}>{t('search')}</PrimaryButton>
                        <button type="button" onClick={() => setShowAdvance(!showAdvance)} className="min-h-[44px] rounded-lg px-3 py-2 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('advance_filter')}</button>
                    </div>
                </form>
            </Card>

            <div className="hidden overflow-x-auto rounded-2xl bg-white shadow md:block dark:bg-zinc-900">
                <table className="w-full min-w-[1400px] text-left text-sm">
                    <thead>
                        <tr className="border-b border-zinc-200 dark:border-zinc-800">
                            {COLUMNS.map(([, label]) => <th key={label} className="whitespace-nowrap px-3 py-3">{t(label)}</th>)}
                            <th className="px-3 py-3">{t('actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((m) => (
                            <Fragment key={m.id}>
                                <tr className="cursor-pointer border-b border-zinc-100 dark:border-zinc-800" onClick={() => setExpanded(expanded === m.id ? null : m.id)}>
                                    {COLUMNS.map(([key]) => <td key={key} className="whitespace-nowrap px-3 py-2">{cell(m, key)}</td>)}
                                    <td className="px-3 py-2" onClick={(e) => e.stopPropagation()}>{actions(m)}</td>
                                </tr>
                                {expanded === m.id && (
                                    <tr className="bg-zinc-50 dark:bg-zinc-800/50">
                                        <td colSpan={COLUMNS.length + 1} className="px-3 py-3 text-sm">
                                            {t('share_amount')}: {m.share_amount} · {t('username')}: {m.username ?? '—'} · {t('status')}: {m.status}
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                        {rows.length === 0 && <tr><td className="px-3 py-3" colSpan={COLUMNS.length + 1}>{t('no_data_found')}</td></tr>}
                    </tbody>
                </table>
            </div>

            <div className="space-y-3 md:hidden">
                {rows.map((m) => (
                    <Card key={m.id}>
                        <div className="flex items-center gap-3">
                            {m.photo_url && <img src={m.photo_url} alt="" className="h-12 w-12 rounded-full object-cover" />}
                            <div>
                                <div className="font-medium">{m.name}</div>
                                <div className="text-sm text-zinc-500">{m.member_no} · {m.mobile}</div>
                                <div className="text-sm text-zinc-500">{m.samity?.name ?? ''}</div>
                            </div>
                        </div>
                        <div className="mt-3">{actions(m)}</div>
                    </Card>
                ))}
                {rows.length === 0 && <Card>{t('no_data_found')}</Card>}
            </div>

            {members.last_page > 1 && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {(members.links ?? []).map((link, i) => (
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
