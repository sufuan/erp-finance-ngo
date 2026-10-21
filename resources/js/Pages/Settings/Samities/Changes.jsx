import { router, useForm } from '@inertiajs/react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../../Components/ui';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

/** Samity day / officer / manager change (Step 7) — one page, 3 types. */
export default function SamityChanges({ type, label, field, samities, history, options, businessDate, selectedSamityId }) {
    const { data, setData, post, processing, errors } = useForm({
        samity_id: selectedSamityId ?? '', new_value: '',
        change_date: businessDate ?? '', effective_from: businessDate ?? '', comments: '',
    });
    const selected = samities.find((s) => String(s.id) === String(data.samity_id));

    const pickSamity = (id) => {
        setData('samity_id', id);
        router.get(`/settings/samities/changes/${type}`, { samity_id: id }, { preserveState: true, preserveScroll: true });
    };
    const submit = (e) => { e.preventDefault(); post(`/settings/samities/changes/${type}`, { preserveScroll: true }); };

    const valueOptions = field === 'samity_day' ? options.days : options.employees;

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
                        <input className={inputCls} value={selected ? (field === 'samity_day' ? t(`day_${String(selected.current).toLowerCase()}`) : selected.current_label ?? '—') : ''} readOnly />
                    </Field>
                    <Field label={t('new_value')} required error={errors.new_value}>
                        <select className={inputCls} value={data.new_value} onChange={(e) => setData('new_value', e.target.value)} required>
                            <option value="">—</option>
                            {valueOptions.map((o) => <option key={o.id} value={o.id}>{o.label_key ? t(o.label_key) : o.name}</option>)}
                        </select>
                    </Field>
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
                        <PrimaryButton type="submit" disabled={processing}>{t('save')}</PrimaryButton>
                    </div>
                </form>
            </Card>

            <Card>
                <h2 className="mb-3 font-medium">{t('history')}</h2>
                <ResponsiveTable head={[t('samity'), t('previous_value'), t('new_value'), t('effective_from'), t('comments')]}>
                    {history.map((h) => (
                        <tr key={h.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{h.samity ? `${h.samity.samity_code} — ${h.samity.name}` : '—'}</td>
                            <td className="px-3 py-2">{field === 'samity_day' && h.previous_label ? t(`day_${String(h.previous_label).toLowerCase()}`) : h.previous_label ?? '—'}</td>
                            <td className="px-3 py-2">{field === 'samity_day' && h.new_label ? t(`day_${String(h.new_label).toLowerCase()}`) : h.new_label ?? '—'}</td>
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
