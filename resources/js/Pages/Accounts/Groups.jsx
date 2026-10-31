import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { ActionButton, Card, Field, PrimaryButton, ResponsiveTable, SelectInput, TextInput } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

export default function Groups({ groups = [], ledgers = [] }) {
    const [editing, setEditing] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '', lines: [{ ledger_id: '', side: 'Debit' }, { ledger_id: '', side: 'Credit' }], is_active: true,
    });

    const startEdit = (g) => {
        setEditing(g);
        setShowForm(true);
        setData({ name: g.name, is_active: g.is_active, lines: g.lines.map((l) => ({ ledger_id: l.ledger_id, side: l.side })) });
    };
    const startNew = () => { setEditing(null); setShowForm(true); reset(); };
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => { setShowForm(false); setEditing(null); reset(); } };
        if (editing) put(`/accounts/groups/${editing.id}`, opts);
        else post('/accounts/groups', opts);
    };
    const setLine = (i, key, value) => setData('lines', data.lines.map((l, idx) => (idx === i ? { ...l, [key]: value } : l)));
    const remove = (g) => {
        if (window.confirm(t('acc_confirm_deactivate'))) {
            router.delete(`/accounts/groups/${g.id}`, { preserveScroll: true });
        }
    };

    return (
        <AppLayout>
            <div className="mx-auto max-w-6xl space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t('acc_ledger_groups')}</h1>
                    <PrimaryButton onClick={startNew}>{t('add_new')}</PrimaryButton>
                </div>
                {showForm && (
                    <Card className="p-4">
                        <form onSubmit={submit} className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label={t('name')} error={errors.name} required>
                                    <TextInput value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                                </Field>
                                <Field label={t('active')}>
                                    <SelectInput value={data.is_active ? '1' : '0'} onChange={(e) => setData('is_active', e.target.value === '1')}>
                                        <option value="1">{t('yes')}</option>
                                        <option value="0">{t('no')}</option>
                                    </SelectInput>
                                </Field>
                            </div>
                            <div className="space-y-2">
                                {data.lines.map((line, i) => (
                                    <div key={i} className="grid grid-cols-12 items-end gap-2">
                                        <div className="col-span-12 sm:col-span-7">
                                            <Field label={i === 0 ? t('ledger') : ''} error={errors[`lines.${i}.ledger_id`]}>
                                                <SelectInput value={line.ledger_id} onChange={(e) => setLine(i, 'ledger_id', e.target.value)}>
                                                    <option value="">{t('select')}</option>
                                                    {ledgers.map((l) => <option key={l.id} value={l.id}>{l.code} — {l.name}</option>)}
                                                </SelectInput>
                                            </Field>
                                        </div>
                                        <div className="col-span-8 sm:col-span-3">
                                            <Field label={i === 0 ? t('acc_side') : ''}>
                                                <SelectInput value={line.side} onChange={(e) => setLine(i, 'side', e.target.value)}>
                                                    <option value="Debit">{t('debit')}</option>
                                                    <option value="Credit">{t('credit')}</option>
                                                </SelectInput>
                                            </Field>
                                        </div>
                                        <div className="col-span-4 sm:col-span-2">
                                            <button type="button" className="rounded border border-slate-300 px-2 py-2 text-xs dark:border-slate-600"
                                                onClick={() => setData('lines', data.lines.filter((_, idx) => idx !== i))} disabled={data.lines.length <= 2}>
                                                {t('remove')}
                                            </button>
                                        </div>
                                    </div>
                                ))}
                                <button type="button" className="rounded border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-600"
                                    onClick={() => setData('lines', [...data.lines, { ledger_id: '', side: 'Debit' }])}>
                                    {t('acc_add_line')}
                                </button>
                            </div>
                            <div className="flex gap-2">
                                <PrimaryButton type="submit" loading={processing}>{t('save')}</PrimaryButton>
                                <button type="button" className="rounded border border-slate-300 px-4 py-2 text-sm dark:border-slate-600" onClick={() => setShowForm(false)}>{t('cancel')}</button>
                            </div>
                        </form>
                    </Card>
                )}
                <Card className="p-0">
                    <ResponsiveTable head={[t('name'), t('acc_lines'), t('status'), '']}>
                        {groups.map((g) => (
                            <tr key={g.id} className="border-t border-slate-100 dark:border-slate-700">
                                <td className="px-3 py-2 font-medium">{g.name}</td>
                                <td className="px-3 py-2">{g.lines.map((l) => `${l.ledger} (${l.side === 'Debit' ? t('debit') : t('credit')})`).join(', ')}</td>
                                <td className="px-3 py-2">{g.is_active ? t('active') : t('inactive')}</td>
                                <td className="px-3 py-2">
                                    <div className="flex justify-end gap-2">
                                        <ActionButton fire={() => startEdit(g)} className="border border-slate-300 text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700">{t('edit')}</ActionButton>
                                        {g.is_active && (
                                            <ActionButton fire={() => remove(g)} className="bg-rose-600 text-white hover:bg-rose-700">{t('deactivate')}</ActionButton>
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {groups.length === 0 && (
                            <tr><td colSpan={4} className="px-3 py-6 text-center text-slate-500">{t('no_data')}</td></tr>
                        )}
                    </ResponsiveTable>
                </Card>
            </div>
        </AppLayout>
    );
}
