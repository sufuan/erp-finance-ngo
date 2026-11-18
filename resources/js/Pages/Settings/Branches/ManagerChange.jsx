import { useForm } from '@inertiajs/react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../../Components/ui';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

/** Branch Manager Change (2026-11-18): branches.manager_id with history,
 *  mirroring the samity officer/manager change pages. */
export default function BranchManagerChange({ branches, employees, history, businessDate }) {
    const { data, setData, post, processing, errors } = useForm({
        branch_id: '', new_value: '',
        change_date: businessDate ?? '', effective_from: businessDate ?? '', comments: '',
    });
    const selected = branches.find((b) => String(b.id) === String(data.branch_id));
    const submit = (e) => { e.preventDefault(); post('/settings/branches/manager-change', { preserveScroll: true }); };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('branch_manager_change')}</h1>

            <Card className="mb-6">
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label={t('branch')} required error={errors.branch_id}>
                        <select className={inputCls} value={data.branch_id} onChange={(e) => setData('branch_id', e.target.value)} required>
                            <option value="">—</option>
                            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('current_value')}>
                        <input className={inputCls} value={selected ? (selected.manager_name ?? '—') : ''} readOnly />
                    </Field>
                    <Field label={t('new_value')} required error={errors.new_value}>
                        <select className={inputCls} value={data.new_value} onChange={(e) => setData('new_value', e.target.value)} required>
                            <option value="">—</option>
                            {employees.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
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
                        <PrimaryButton type="submit" loading={processing}>{t('save')}</PrimaryButton>
                    </div>
                </form>
            </Card>

            <Card>
                <h2 className="mb-3 font-medium">{t('history')}</h2>
                <ResponsiveTable head={[t('branch'), t('previous_value'), t('new_value'), t('effective_from'), t('comments')]}>
                    {history.map((h) => (
                        <tr key={h.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{h.branch ? `${h.branch.code} — ${h.branch.name}` : '—'}</td>
                            <td className="px-3 py-2">{h.previous_label ?? '—'}</td>
                            <td className="px-3 py-2">{h.new_label ?? '—'}</td>
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
