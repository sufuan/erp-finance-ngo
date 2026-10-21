import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../../Components/ui';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

const emptyForm = {
    branch_id: '', name: '', referred_by: '', address: '', open_date: '', working_area_id: '',
    field_officer_id: '', samity_phone: '', center_type: 'All Gender', start_time: '',
    samity_day: 'Wednesday', date_of_month: '', manager_id: '', latitude: '', longitude: '',
};

export default function SamitiesIndex({ samities, options, businessDate, activeBranchId }) {
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        ...emptyForm, branch_id: activeBranchId ?? '', open_date: businessDate ?? '',
    });

    const startCreate = () => { setEditing(null); reset(); setData({ ...emptyForm, branch_id: activeBranchId ?? '', open_date: businessDate ?? '' }); };
    const startEdit = (s) => {
        setEditing(s);
        const next = {};
        Object.keys(emptyForm).forEach((k) => {
            let v = s[k] ?? '';
            if (k === 'open_date' && typeof v === 'string') v = v.slice(0, 10);
            if (k === 'start_time' && typeof v === 'string') v = v.slice(0, 5);
            next[k] = v;
        });
        setData(next);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };
    const submit = (e) => {
        e.preventDefault();
        if (editing) put(`/settings/samities/${editing.id}`, { preserveScroll: true, onSuccess: startCreate });
        else post('/settings/samities', { preserveScroll: true, onSuccess: startCreate });
    };
    const useMyLocation = () => {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition((pos) => {
            setData((d) => ({ ...d, latitude: pos.coords.latitude.toFixed(7), longitude: pos.coords.longitude.toFixed(7) }));
        });
    };
    const mapUrl = data.latitude && data.longitude
        ? `https://www.openstreetmap.org/?mlat=${data.latitude}&mlon=${data.longitude}#map=15/${data.latitude}/${data.longitude}` : null;

    const select = (key, opts, label, required = false) => (
        <Field label={t(label)} required={required} error={errors[key]}>
            <select className={inputCls} value={data[key]} onChange={(e) => setData(key, e.target.value)} required={required}>
                <option value="">—</option>
                {opts.map((o) => <option key={o.id} value={o.id}>{o.label_key ? t(o.label_key) : o.name}</option>)}
            </select>
        </Field>
    );
    const text = (key, label, type = 'text', required = false) => (
        <Field label={t(label)} required={required} error={errors[key]}>
            <input type={type} className={inputCls} value={data[key]} onChange={(e) => setData(key, e.target.value)} required={required} />
        </Field>
    );

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('samity_opening')}</h1>

            <div className="mb-4 flex flex-wrap gap-2">
                {[['day', 'samity_day_change'], ['officer', 'samity_officer_change'], ['manager', 'samity_manager_change']].map(([type, key]) => (
                    <Link key={type} href={`/settings/samities/changes/${type}`} className="min-h-[44px] rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700">{t(key)}</Link>
                ))}
                <Link href="/settings/samities/merge" className="min-h-[44px] rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700">{t('samity_merge')}</Link>
                <Link href="/settings/samities/split" className="min-h-[44px] rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700">{t('samity_split')}</Link>
            </div>

            <Card className="mb-6">
                <h2 className="mb-3 font-medium">{editing ? t('edit') : t('new_samity')}</h2>
                <form onSubmit={submit}>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {select('branch_id', options.branches, 'branch', true)}
                        {text('name', 'samity_name', 'text', true)}
                        {text('referred_by', 'referred_by')}
                        {text('open_date', 'open_date', 'date', true)}
                        {select('working_area_id', options.workingAreas, 'working_area')}
                        {select('field_officer_id', options.employees, 'field_officer')}
                        {text('samity_phone', 'samity_phone')}
                        {select('center_type', options.centerTypes, 'center_type', true)}
                        {text('start_time', 'start_time', 'time')}
                        {select('samity_day', options.days, 'samity_day', true)}
                        {text('date_of_month', 'date_of_month', 'number')}
                        {select('manager_id', options.employees, 'manager')}
                        {text('latitude', 'latitude', 'number')}
                        {text('longitude', 'longitude', 'number')}
                    </div>
                    <Field label={t('address')} error={errors.address}>
                        <textarea className={inputCls} rows={2} value={data.address} onChange={(e) => setData('address', e.target.value)} />
                    </Field>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <button type="button" onClick={useMyLocation} className="min-h-[44px] rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700">{t('map_location')}</button>
                        {mapUrl && <a href={mapUrl} target="_blank" rel="noreferrer" className="min-h-[44px] rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700">{t('view_on_map')}</a>}
                    </div>
                    <div className="mt-4 flex gap-2">
                        <PrimaryButton type="submit" disabled={processing}>{t('save')}</PrimaryButton>
                        {editing && <button type="button" onClick={startCreate} className="min-h-[44px] rounded-lg px-4 py-2 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('cancel')}</button>}
                    </div>
                </form>
            </Card>

            <Card>
                <ResponsiveTable head={[t('samity_code'), t('samity_name'), t('branch'), t('samity_day'), t('field_officer'), t('members'), t('status'), t('actions')]}>
                    {samities.map((s) => (
                        <tr key={s.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{s.samity_code}</td>
                            <td className="px-3 py-2">{s.name}</td>
                            <td className="px-3 py-2">{s.branch ? `${s.branch.code}` : '—'}</td>
                            <td className="px-3 py-2">{t(`day_${String(s.samity_day).toLowerCase()}`)}</td>
                            <td className="px-3 py-2">{s.field_officer?.name ?? '—'}</td>
                            <td className="px-3 py-2">{s.member_count}</td>
                            <td className="px-3 py-2">{s.status}</td>
                            <td className="px-3 py-2">
                                <button type="button" onClick={() => startEdit(s)} className="rounded-lg px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">{t('edit')}</button>
                            </td>
                        </tr>
                    ))}
                    {samities.length === 0 && <tr><td className="px-3 py-3" colSpan={8}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
