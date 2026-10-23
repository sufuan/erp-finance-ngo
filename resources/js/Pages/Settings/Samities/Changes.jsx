import { router, useForm } from '@inertiajs/react';
import SamitySchedulePicker, { scheduleLabel } from '../../../Components/SamitySchedulePicker';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../../Components/ui';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

const scheduleOf = (s) => {
    const monthly = s?.current_date !== null && s?.current_date !== undefined && s?.current_date !== '';

    return {
        meeting_frequency: monthly ? 'monthly' : 'weekly',
        samity_day: monthly ? '' : (s?.current_day ?? 'Wednesday'),
        date_of_month: monthly ? String(s.current_date) : '',
    };
};

/** Samity day / officer / manager change (Step 7) — one page, 3 types. */
export default function SamityChanges({ type, label, field, samities, history, options, businessDate, selectedSamityId }) {
    const initial = samities.find((s) => String(s.id) === String(selectedSamityId ?? ''));
    const { data, setData, post, processing, errors } = useForm({
        samity_id: selectedSamityId ?? '', new_value: '',
        ...(field === 'samity_day' ? scheduleOf(initial) : { meeting_frequency: 'weekly', samity_day: 'Wednesday', date_of_month: '' }),
        change_date: businessDate ?? '', effective_from: businessDate ?? '', comments: '',
    });
    const selected = samities.find((s) => String(s.id) === String(data.samity_id));
    const isDay = field === 'samity_day';

    const pickSamity = (id) => {
        const s = samities.find((x) => String(x.id) === String(id));
        setData((d) => ({ ...d, samity_id: id, ...(isDay ? scheduleOf(s) : {}) }));
        router.get(`/settings/samities/changes/${type}`, { samity_id: id }, { preserveState: true, preserveScroll: true });
    };
    const submit = (e) => { e.preventDefault(); post(`/settings/samities/changes/${type}`, { preserveScroll: true }); };
    const changeFrequency = (f) => setData((d) => ({
        ...d,
        meeting_frequency: f,
        samity_day: f === 'monthly' ? '' : (d.samity_day || 'Wednesday'),
        date_of_month: f === 'weekly' ? '' : d.date_of_month,
    }));

    const valueOptions = options.employees;

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t(label)}</h1>

            <div className="mb-4 flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-800">
                {[['day', 'samity_day_change'], ['officer', 'samity_officer_change'], ['manager', 'samity_manager_change']].map(([tp, key]) => (
                    <a key={tp} href={`/settings/samities/changes/${tp}`}
                        className={`min-h-[44px] whitespace-nowrap px-3 py-2 text-sm font-medium ${type === tp ? 'border-b-2 border-emerald-600 text-emerald-700 dark:text-emerald-400' : 'text-zinc-500'}`}>
                        {t(key)}
                    </a>
                ))}
            </div>

            <Card className="mb-6">
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label={t('samity')} required error={errors.samity_id}>
                        <select className={inputCls} value={data.samity_id} onChange={(e) => pickSamity(e.target.value)} required>
                            <option value="">—</option>
                            {samities.map((s) => <option key={s.id} value={s.id}>{s.samity_code} — {s.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('current_value')}>
                        <input className={inputCls} value={selected ? (isDay ? scheduleLabel(selected.current) : selected.current_label ?? '—') : ''} readOnly />
                    </Field>
                    {!isDay && (
                        <Field label={t('new_value')} required error={errors.new_value}>
                            <select className={inputCls} value={data.new_value} onChange={(e) => setData('new_value', e.target.value)} required>
                                <option value="">—</option>
                                {valueOptions.map((o) => <option key={o.id} value={o.id}>{o.label_key ? t(o.label_key) : o.name}</option>)}
                            </select>
                        </Field>
                    )}
                    {isDay && (
                        <div className="sm:col-span-2 lg:col-span-3">
                            <SamitySchedulePicker
                                frequency={data.meeting_frequency} day={data.samity_day} dateOfMonth={data.date_of_month}
                                days={options.days} errors={errors}
                                onFrequency={changeFrequency}
                                onDay={(d) => setData('samity_day', d)}
                                onDate={(v) => setData('date_of_month', v)} />
                        </div>
                    )}
                    <Field label={t('date')} required error={errors.change_date}>
                        <input type="date" className={inputCls} value={data.change_date} onChange={(e) => setData('change_date', e.target.value)} required />
                    </Field>
                    <Field label={t('effective_from')} required error={errors.effective_from}>
                        <input type="date" className={inputCls} value={data.effective_from} onChange={(e) => setData('effective_from', e.target.value)} required />
                    </Field>
                    <Field label={t('comments')} error={errors.comments}>
                        <input className={inputCls} value={data.comments} onChange={(e) => setData('comments', e.target.value)} />
                    </Field>
                    <div className="sm:col-span-2 lg:col-span-3">
                        <PrimaryButton type="submit" loading={processing}>{t('save')}</PrimaryButton>
                    </div>
                </form>
            </Card>

            <Card>
                <h2 className="mb-3 font-medium">{t('history')}</h2>
                <ResponsiveTable head={[t('samity'), t('previous_value'), t('new_value'), t('effective_from'), t('comments')]}>
                    {history.map((h) => (
                        <tr key={h.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{h.samity ? `${h.samity.samity_code} — ${h.samity.name}` : '—'}</td>
                            <td className="px-3 py-2">{isDay ? scheduleLabel(h.previous_label) : h.previous_label ?? '—'}</td>
                            <td className="px-3 py-2">{isDay ? scheduleLabel(h.new_label) : h.new_label ?? '—'}</td>
                            <td className="px-3 py-2">{String(h.effective_from).slice(0, 10)}</td>
                            <td className="px-3 py-2">{h.comments ?? '—'}</td>
                        </tr>
                    ))}
                    {history.length === 0 && <tr><td className="px-3 py-3" colSpan={5}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
