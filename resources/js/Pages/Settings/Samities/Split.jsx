import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import SamitySchedulePicker from '../../../Components/SamitySchedulePicker';
import { Card, Field, PrimaryButton, inputCls } from '../../../Components/ui';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

/** Samity split (Step 7): chosen members move into a brand-new samity. */
export default function SamitySplit({ samities, members, options }) {
    const [sourceId, setSourceId] = useState('');
    const source = samities.find((s) => String(s.id) === String(sourceId))?.source;
    const { data, setData, post, processing, errors } = useForm({
        source_samity_id: '', member_ids: [], manager_approved: false,
        branch_id: '', name: '', referred_by: '', address: '', open_date: '', working_area_id: '',
        field_officer_id: '', samity_phone: '', center_type: 'All Gender', start_time: '',
        meeting_frequency: 'weekly', samity_day: 'Wednesday', date_of_month: '', manager_id: '', latitude: '', longitude: '',
    });

    const pickSource = (id) => {
        setSourceId(id);
        const s = samities.find((x) => String(x.id) === String(id))?.source;
        const monthly = s?.date_of_month !== null && s?.date_of_month !== undefined && s?.date_of_month !== '';
        setData((d) => ({
            ...d, source_samity_id: id, member_ids: [],
            branch_id: s?.branch_id ?? '', working_area_id: s?.working_area_id ?? '',
            field_officer_id: s?.field_officer_id ?? '', manager_id: s?.manager_id ?? '',
            meeting_frequency: monthly ? 'monthly' : 'weekly',
            samity_day: monthly ? '' : (s?.samity_day ?? 'Wednesday'),
            date_of_month: monthly ? String(s.date_of_month) : '',
            center_type: s?.center_type ?? 'All Gender', address: s?.address ?? '',
        }));
    };
    const changeFrequency = (f) => setData((d) => ({
        ...d,
        meeting_frequency: f,
        samity_day: f === 'monthly' ? '' : (d.samity_day || 'Wednesday'),
        date_of_month: f === 'weekly' ? '' : d.date_of_month,
    }));
    const toggleMember = (id, checked) => {
        setData('member_ids', checked ? [...data.member_ids, id] : data.member_ids.filter((m) => m !== id));
    };
    const submit = (e) => { e.preventDefault(); post('/settings/samities/split', { preserveScroll: true }); };
    const sourceMembers = members.filter((m) => String(m.samity_id) === String(sourceId));

    const select = (key, opts, label, required = false) => (
        <Field label={t(label)} required={required} error={errors[key]}>
            <select className={inputCls} value={data[key]} onChange={(e) => setData(key, e.target.value)} required={required}>
                <option value="">—</option>
                {opts.map((o) => <option key={o.id} value={o.id}>{o.label_key ? t(o.label_key) : o.name}</option>)}
            </select>
        </Field>
    );

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('samity_split')}</h1>
            <Card className="mb-6">
                <form onSubmit={submit}>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Field label={t('source_samity')} required error={errors.source_samity_id}>
                            <select className={inputCls} value={sourceId} onChange={(e) => pickSource(e.target.value)} required>
                                <option value="">—</option>
                                {samities.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </select>
                        </Field>
                        <Field label={t('samity_name')} required error={errors.name}>
                            <input className={inputCls} value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                        </Field>
                        <Field label={t('open_date')} required error={errors.open_date}>
                            <input type="date" className={inputCls} value={data.open_date} onChange={(e) => setData('open_date', e.target.value)} required />
                        </Field>
                        {select('field_officer_id', options.employees, 'field_officer')}
                        {select('center_type', options.centerTypes, 'center_type', true)}
                    </div>
                    <div className="mt-4">
                        <SamitySchedulePicker
                            frequency={data.meeting_frequency} day={data.samity_day} dateOfMonth={data.date_of_month}
                            days={options.days} errors={errors}
                            onFrequency={changeFrequency}
                            onDay={(d) => setData('samity_day', d)}
                            onDate={(v) => setData('date_of_month', v)} />
                    </div>

                    <h2 className="mb-2 mt-6 font-medium">{t('select_members')}</h2>
                    {errors.member_ids && <p className="mb-2 text-sm text-red-600 dark:text-red-400">{errors.member_ids}</p>}
                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {sourceMembers.map((m) => (
                            <label key={m.id} className="flex min-h-[44px] items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-800">
                                <input type="checkbox" className="h-5 w-5" checked={data.member_ids.includes(m.id)} onChange={(e) => toggleMember(m.id, e.target.checked)} />
                                {m.member_no} — {m.name}
                            </label>
                        ))}
                        {sourceId && sourceMembers.length === 0 && <p className="text-sm text-zinc-500">{t('no_data_found')}</p>}
                    </div>

                    <label className="mt-4 flex min-h-[44px] items-center gap-2 text-sm">
                        <input type="checkbox" className="h-5 w-5" checked={!!data.manager_approved} onChange={(e) => setData('manager_approved', e.target.checked)} />
                        {t('manager_approved')}
                    </label>
                    {errors.manager_approved && <span className="text-sm text-red-600 dark:text-red-400">{errors.manager_approved}</span>}

                    <div className="mt-4">
                        <PrimaryButton type="submit" loading={processing}>{t('split')}</PrimaryButton>
                    </div>
                </form>
            </Card>
            {source && <p className="text-sm text-zinc-500">{t('split_help')}</p>}
        </AppLayout>
    );
}
