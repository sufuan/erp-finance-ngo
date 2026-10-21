import { useForm } from '@inertiajs/react';
import { Card, Field, PrimaryButton, inputCls } from '../../../Components/ui';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

/** Samity merge (Step 7): all members of source move into target. */
export default function SamityMerge({ samities }) {
    const { data, setData, post, processing, errors } = useForm({
        source_samity_id: '', target_samity_id: '', manager_approved: false,
    });
    const submit = (e) => { e.preventDefault(); post('/settings/samities/merge', { preserveScroll: true }); };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('samity_merge')}</h1>
            <p className="mb-4 text-sm text-zinc-500 dark:text-zinc-400">{t('merge_help')}</p>
            <Card>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('source_samity')} required error={errors.source_samity_id}>
                        <select className={inputCls} value={data.source_samity_id} onChange={(e) => setData('source_samity_id', e.target.value)} required>
                            <option value="">—</option>
                            {samities.map((s) => <option key={s.id} value={s.id}>{s.name} ({s.member_count})</option>)}
                        </select>
                    </Field>
                    <Field label={t('target_samity')} required error={errors.target_samity_id}>
                        <select className={inputCls} value={data.target_samity_id} onChange={(e) => setData('target_samity_id', e.target.value)} required>
                            <option value="">—</option>
                            {samities.map((s) => <option key={s.id} value={s.id}>{s.name} ({s.member_count})</option>)}
                        </select>
                    </Field>
                    <label className="flex min-h-[44px] items-center gap-2 text-sm">
                        <input type="checkbox" className="h-5 w-5" checked={!!data.manager_approved} onChange={(e) => setData('manager_approved', e.target.checked)} />
                        {t('manager_approved')}
                    </label>
                    {errors.manager_approved && <span className="text-sm text-red-600 dark:text-red-400">{errors.manager_approved}</span>}
                    <div className="sm:col-span-2">
                        <PrimaryButton type="submit" disabled={processing}>{t('merge')}</PrimaryButton>
                    </div>
                </form>
            </Card>
        </AppLayout>
    );
}
