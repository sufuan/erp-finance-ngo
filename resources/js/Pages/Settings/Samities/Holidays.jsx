import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ActionButton, Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../../Components/ui';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

/**
 * Holiday management (Step 7): single date or a range (max 31 days).
 * Scope (2026-11-18): 'all' = every branch at once (branch_id NULL row),
 * 'branch' = one branch (optionally one samity), as before. Each list row
 * can be edited (single day) or deleted; an all-branches row edits/deletes
 * for every branch at once.
 */
export default function Holidays({ holidays, options, samities, activeBranchId, businessDate }) {
    const [mode, setMode] = useState('single');
    const [editingId, setEditingId] = useState(null);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        scope: 'branch', branch_id: activeBranchId ?? '', samity_id: '', details: '',
        holiday_date: businessDate ?? '', date_from: '', date_to: '',
    });
    const submit = (e) => {
        e.preventDefault();
        if (editingId) {
            put(`/settings/holidays/${editingId}`, {
                preserveScroll: true,
                onSuccess: () => { setEditingId(null); reset(); },
            });
        } else {
            post('/settings/holidays', { preserveScroll: true });
        }
    };
    const startEdit = (h) => {
        setEditingId(h.id);
        setMode('single');
        setData({
            scope: h.branch_id ? 'branch' : 'all',
            branch_id: h.branch_id ?? '',
            samity_id: h.samity_id ?? '',
            details: h.details ?? '',
            holiday_date: String(h.holiday_date).slice(0, 10),
            date_from: '', date_to: '',
        });
    };
    const cancelEdit = () => { setEditingId(null); reset(); };

    const toggleCls = (active) => `min-h-[44px] rounded-lg px-3 py-2 text-sm ${active ? 'bg-emerald-600 text-white' : 'border border-zinc-300 dark:border-zinc-700'}`;

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('holidays')}</h1>
            <p className="mb-4 text-sm text-zinc-500 dark:text-zinc-400">{t('holiday_help')}</p>

            <Card className="mb-6">
                {!editingId && (
                    <div className="mb-4 flex gap-2">
                        {['single', 'range'].map((m) => (
                            <button key={m} type="button" onClick={() => setMode(m)} className={toggleCls(mode === m)}>
                                {t(m === 'single' ? 'single_date' : 'date_range')}
                            </button>
                        ))}
                    </div>
                )}
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label={t('holiday_scope')} required error={errors.scope}>
                        <div className="flex gap-2">
                            {['all', 'branch'].map((s) => (
                                <button key={s} type="button" onClick={() => setData('scope', s)} className={toggleCls(data.scope === s)}>
                                    {t(s === 'all' ? 'all_branches' : 'branch_wise')}
                                </button>
                            ))}
                        </div>
                    </Field>
                    {data.scope === 'branch' && (
                        <>
                            <Field label={t('branch')} required error={errors.branch_id}>
                                <select className={inputCls} value={data.branch_id} onChange={(e) => setData('branch_id', e.target.value)} required>
                                    <option value="">—</option>
                                    {options.branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                                </select>
                            </Field>
                            <Field label={t('samity')} error={errors.samity_id}>
                                <select className={inputCls} value={data.samity_id} onChange={(e) => setData('samity_id', e.target.value)}>
                                    <option value="">—</option>
                                    {samities.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                </select>
                            </Field>
                        </>
                    )}
                    <Field label={t('holiday_details')} required error={errors.details}>
                        <input className={inputCls} value={data.details} onChange={(e) => setData('details', e.target.value)} required />
                    </Field>
                    {mode === 'single' || editingId ? (
                        <Field label={t('holiday_date')} required error={errors.holiday_date}>
                            <input type="date" className={inputCls} value={data.holiday_date} onChange={(e) => setData('holiday_date', e.target.value)} required />
                        </Field>
                    ) : (
                        <>
                            <Field label={t('date_from')} required error={errors.date_from}>
                                <input type="date" className={inputCls} value={data.date_from} onChange={(e) => setData('date_from', e.target.value)} required />
                            </Field>
                            <Field label={t('date_to')} required error={errors.date_to}>
                                <input type="date" className={inputCls} value={data.date_to} onChange={(e) => setData('date_to', e.target.value)} required />
                            </Field>
                        </>
                    )}
                    <div className="flex gap-2 sm:col-span-2 lg:col-span-3">
                        <PrimaryButton type="submit" loading={processing}>{t('save')}</PrimaryButton>
                        {editingId && (
                            <button type="button" onClick={cancelEdit} className="min-h-[44px] rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700">
                                {t('cancel')}
                            </button>
                        )}
                    </div>
                </form>
            </Card>

            <Card>
                <ResponsiveTable head={[t('holiday_date'), t('branch'), t('samity'), t('holiday_details'), t('actions')]}>
                    {holidays.map((h) => (
                        <tr key={h.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{String(h.holiday_date).slice(0, 10)}</td>
                            <td className="px-3 py-2">{h.branch?.name ?? t('all_branches')}</td>
                            <td className="px-3 py-2">{h.samity?.name ?? '—'}</td>
                            <td className="px-3 py-2">{h.details}</td>
                            <td className="px-3 py-2">
                                <div className="flex gap-1">
                                    <button type="button" onClick={() => startEdit(h)} className="min-h-[36px] rounded-lg px-2 py-1 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/20">{t('edit')}</button>
                                    <ActionButton fire={(cb) => router.delete(`/settings/holidays/${h.id}`, { preserveScroll: true, ...cb })} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('delete')}</ActionButton>
                                </div>
                            </td>
                        </tr>
                    ))}
                    {holidays.length === 0 && <tr><td className="px-3 py-3" colSpan={5}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
