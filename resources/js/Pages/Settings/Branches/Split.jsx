import { useForm } from '@inertiajs/react';
import { Card, Field, PrimaryButton, inputCls } from '../../../Components/ui';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

/** Branch Split (2026-11-18): ticked samities of the source branch move
 *  out into a brand-new branch (code auto-assigned by the server). */
export default function BranchSplit({ branches, samities, nextCode }) {
    const { data, setData, post, processing, errors } = useForm({
        source_branch_id: '', samity_ids: [], new_branch_name: '',
    });
    const sourceSamities = samities.filter((s) => String(s.branch_id) === String(data.source_branch_id));
    const toggle = (id, checked) => {
        setData('samity_ids', checked
            ? [...data.samity_ids, id]
            : data.samity_ids.filter((x) => String(x) !== String(id)));
    };
    const submit = (e) => { e.preventDefault(); post('/settings/branches/split', { preserveScroll: true }); };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('branch_split')}</h1>
            <p className="mb-4 text-sm text-zinc-500 dark:text-zinc-400">{t('branch_split_help')}</p>
            <Card>
                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('source_branch')} required error={errors.source_branch_id}>
                            <select className={inputCls} value={data.source_branch_id}
                                onChange={(e) => setData({ ...data, source_branch_id: e.target.value, samity_ids: [] })} required>
                                <option value="">—</option>
                                {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                            </select>
                        </Field>
                        <Field label={t('new_branch_name')} required error={errors.new_branch_name}>
                            <input className={inputCls} value={data.new_branch_name} onChange={(e) => setData('new_branch_name', e.target.value)} required />
                        </Field>
                        <Field label={t('new_branch_code')}>
                            <input className={inputCls} value={nextCode ?? ''} readOnly />
                        </Field>
                    </div>

                    <Field label={t('samities_to_move')} required error={errors.samity_ids}>
                        <div className="grid gap-1 sm:grid-cols-2">
                            {sourceSamities.map((s) => (
                                <label key={s.id} className="flex min-h-[44px] items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700">
                                    <input type="checkbox" className="h-5 w-5"
                                        checked={data.samity_ids.some((x) => String(x) === String(s.id))}
                                        onChange={(e) => toggle(s.id, e.target.checked)} />
                                    <span>{s.name} ({s.member_count})</span>
                                </label>
                            ))}
                            {data.source_branch_id && sourceSamities.length === 0 && (
                                <p className="text-sm text-zinc-500 dark:text-zinc-400">{t('no_data_found')}</p>
                            )}
                        </div>
                    </Field>

                    <div>
                        <PrimaryButton type="submit" loading={processing} disabled={!data.source_branch_id || data.samity_ids.length === 0}>
                            {t('split')}
                        </PrimaryButton>
                    </div>
                </form>
            </Card>
        </AppLayout>
    );
}
