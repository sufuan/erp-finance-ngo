import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, inputCls } from '../../../Components/ui';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

/** Branch Merge (2026-11-18): all samities of the source branch move
 *  into the target branch; the source branch is then closed. The form
 *  shows exactly what will move and asks for an explicit confirmation
 *  before the Merge button unlocks. */
export default function BranchMerge({ branches }) {
    const { data, setData, post, processing, errors } = useForm({
        source_branch_id: '', target_branch_id: '',
    });
    const [confirmed, setConfirmed] = useState(false);
    const source = branches.find((b) => String(b.id) === String(data.source_branch_id));
    const target = branches.find((b) => String(b.id) === String(data.target_branch_id));
    const ready = source && target && String(source.id) !== String(target.id);
    const submit = (e) => { e.preventDefault(); post('/settings/branches/merge', { preserveScroll: true }); };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('branch_merge')}</h1>
            <p className="mb-4 text-sm text-zinc-500 dark:text-zinc-400">{t('branch_merge_help')}</p>
            <Card>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('source_branch')} required error={errors.source_branch_id}>
                        <select className={inputCls} value={data.source_branch_id}
                            onChange={(e) => { setData('source_branch_id', e.target.value); setConfirmed(false); }} required>
                            <option value="">—</option>
                            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('target_branch')} required error={errors.target_branch_id}>
                        <select className={inputCls} value={data.target_branch_id}
                            onChange={(e) => { setData('target_branch_id', e.target.value); setConfirmed(false); }} required>
                            <option value="">—</option>
                            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                        </select>
                    </Field>

                    {ready && (
                        <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950 sm:col-span-2">
                            <p className="mb-1 font-medium">{t('what_will_move')}</p>
                            <p>{source.name}: {t('samities')} — {source.samity_count}, {t('members')} — {source.member_count} → {target.name}</p>
                        </div>
                    )}

                    <label className="flex min-h-[44px] items-center gap-2 text-sm sm:col-span-2">
                        <input type="checkbox" className="h-5 w-5" checked={confirmed} disabled={!ready}
                            onChange={(e) => setConfirmed(e.target.checked)} />
                        {t('confirm_branch_merge')}
                    </label>

                    <div className="sm:col-span-2">
                        <PrimaryButton type="submit" loading={processing} disabled={!ready || !confirmed}>{t('merge')}</PrimaryButton>
                    </div>
                </form>
            </Card>
        </AppLayout>
    );
}
