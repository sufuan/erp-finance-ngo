import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { ActionButton, Card, Field, PrimaryButton, ResponsiveTable, SelectInput, TextInput } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

export default function Recurring({ rows = [], ledgers = [], businessDate = null }) {
    const [editing, setEditing] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [busy, setBusy] = useState(null);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '', debit_ledger_id: '', credit_ledger_id: '', amount: '', frequency: 'Monthly', next_run_date: '', is_active: true,
    });

    const startEdit = (s) => {
        setEditing(s);
        setShowForm(true);
        setData({ name: s.name, debit_ledger_id: s.debit_ledger_id, credit_ledger_id: s.credit_ledger_id, amount: s.amount, frequency: s.frequency, next_run_date: s.next_run_date, is_active: s.is_active });
    };
    const startNew = () => { setEditing(null); setShowForm(true); reset(); setData('next_run_date', businessDate ?? ''); };
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => { setShowForm(false); setEditing(null); reset(); } };
        if (editing) put(`/accounts/recurring/${editing.id}`, opts);
        else post('/accounts/recurring', opts);
    };
    const postNow = (s) => {
        setBusy(s.id);
        router.post(`/accounts/recurring/${s.id}/post`, {}, { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    return (
        <AppLayout>
            <div className="mx-auto max-w-6xl space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t('acc_recurring_setup')}</h1>
                    <PrimaryButton onClick={startNew}>{t('add_new')}</PrimaryButton>
                </div>
                {showForm && (
                    <Card className="p-4">
                        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-3">
                            <Field label={t('name')} error={errors.name} required>
                                <TextInput value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                            </Field>
                            <Field label={t('acc_debit_ledger')} error={errors.debit_ledger_id} required>
                                <SelectInput value={data.debit_ledger_id} onChange={(e) => setData('debit_ledger_id', e.target.value)}>
                                    <option value="">{t('select')}</option>
                                    {ledgers.map((l) => <option key={l.id} value={l.id}>{l.code} — {l.name}</option>)}
                                </SelectInput>
                            </Field>
                            <Field label={t('acc_credit_ledger')} error={errors.credit_ledger_id} required>
                                <SelectInput value={data.credit_ledger_id} onChange={(e) => setData('credit_ledger_id', e.target.value)}>
                                    <option value="">{t('select')}</option>
                                    {ledgers.map((l) => <option key={l.id} value={l.id}>{l.code} — {l.name}</option>)}
                                </SelectInput>
                            </Field>
                            <Field label={t('amount')} error={errors.amount} required>
                                <TextInput type="number" step="0.01" min="0.01" value={data.amount} onChange={(e) => setData('amount', e.target.value)} required />
                            </Field>
                            <Field label={t('acc_frequency')} error={errors.frequency} required>
                                <SelectInput value={data.frequency} onChange={(e) => setData('frequency', e.target.value)}>
                                    <option value="Daily">{t('acc_freq_daily')}</option>
                                    <option value="Weekly">{t('acc_freq_weekly')}</option>
                                    <option value="Monthly">{t('acc_freq_monthly')}</option>
                                </SelectInput>
                            </Field>
                            <Field label={t('acc_next_run')} error={errors.next_run_date} required>
                                <TextInput type="date" value={data.next_run_date} onChange={(e) => setData('next_run_date', e.target.value)} required />
                            </Field>
                            {editing && (
                                <Field label={t('active')}>
                                    <SelectInput value={data.is_active ? '1' : '0'} onChange={(e) => setData('is_active', e.target.value === '1')}>
                                        <option value="1">{t('yes')}</option>
                                        <option value="0">{t('no')}</option>
                                    </SelectInput>
                                </Field>
                            )}
                            <div className="flex items-end gap-2">
                                <PrimaryButton type="submit" loading={processing}>{t('save')}</PrimaryButton>
                                <button type="button" className="rounded border border-slate-300 px-4 py-2 text-sm dark:border-slate-600" onClick={() => setShowForm(false)}>{t('cancel')}</button>
                            </div>
                        </form>
                    </Card>
                )}
                <Card className="p-0">
                    <ResponsiveTable head={[t('name'), t('acc_debit_ledger'), t('acc_credit_ledger'), t('amount'), t('acc_frequency'), t('acc_next_run'), t('status'), '']}>
                        {rows.map((s) => (
                            <tr key={s.id} className="border-t border-slate-100 dark:border-slate-700">
                                <td className="px-3 py-2 font-medium">{s.name}</td>
                                <td className="px-3 py-2">{s.debit}</td>
                                <td className="px-3 py-2">{s.credit}</td>
                                <td className="px-3 py-2 text-right">{Number(s.amount).toLocaleString()}</td>
                                <td className="px-3 py-2">{t('acc_freq_' + s.frequency.toLowerCase())}</td>
                                <td className="px-3 py-2">{s.next_run_date} {s.due && <span className="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{t('acc_due')}</span>}</td>
                                <td className="px-3 py-2">{s.is_active ? t('active') : t('inactive')}</td>
                                <td className="px-3 py-2">
                                    <div className="flex justify-end gap-2">
                                        <ActionButton fire={() => startEdit(s)} className="border border-slate-300 text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700">{t('edit')}</ActionButton>
                                        {s.is_active && (
                                            <ActionButton fire={() => postNow(s)} disabled={busy === s.id} className="bg-cyan-600 text-white hover:bg-cyan-700">{t('acc_post_now')}</ActionButton>
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {rows.length === 0 && (
                            <tr><td colSpan={8} className="px-3 py-6 text-center text-slate-500">{t('no_data')}</td></tr>
                        )}
                    </ResponsiveTable>
                </Card>
            </div>
        </AppLayout>
    );
}
