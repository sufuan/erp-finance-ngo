import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ActionButton, Card, Field, PrimaryButton, ResponsiveTable, SelectInput, TextInput } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

const GROUPS = ['Asset', 'Liability', 'Equity', 'Income', 'Expense'];

export default function Ledgers({ ledgers = [], suggestedCode = '', branches = [], branchId = null }) {
    const [editing, setEditing] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        ledger_code: '', name_en: '', name_bn: '', ledger_group: 'Expense', opening_balance: '0', is_active: true,
    });

    const startEdit = (l) => {
        setEditing(l);
        setShowForm(true);
        setData({ ledger_code: l.code, name_en: l.name, name_bn: l.name_bn ?? '', ledger_group: l.group, opening_balance: '0', is_active: l.is_active });
    };
    const startNew = () => {
        setEditing(null);
        setShowForm(true);
        reset();
        setData('ledger_code', suggestedCode);
    };

    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => { setShowForm(false); setEditing(null); reset(); } };
        if (editing) put(`/accounts/ledgers/${editing.id}`, opts);
        else post('/accounts/ledgers', opts);
    };

    return (
        <AppLayout>
            <div className="mx-auto max-w-6xl space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t('acc_account_list')}</h1>
                    <PrimaryButton onClick={startNew}>{t('acc_new_account_opening')}</PrimaryButton>
                </div>
                {showForm && (
                    <Card className="p-4">
                        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-3">
                            <Field label={t('acc_ledger_code')} error={errors.ledger_code} required>
                                <TextInput value={data.ledger_code} onChange={(e) => setData('ledger_code', e.target.value)} disabled={!!editing} required />
                            </Field>
                            <Field label={t('ledger_group')} error={errors.ledger_group} required>
                                <SelectInput value={data.ledger_group} onChange={(e) => setData('ledger_group', e.target.value)} disabled={!!editing}>
                                    {GROUPS.map((g) => <option key={g} value={g}>{t('acc_group_' + g.toLowerCase())}</option>)}
                                </SelectInput>
                            </Field>
                            <Field label={t('acc_opening_balance')} error={errors.opening_balance}>
                                <TextInput type="number" step="0.01" min="0" value={data.opening_balance} onChange={(e) => setData('opening_balance', e.target.value)} disabled={!!editing} />
                            </Field>
                            <Field label={t('name_en')} error={errors.name_en} required>
                                <TextInput value={data.name_en} onChange={(e) => setData('name_en', e.target.value)} required />
                            </Field>
                            <Field label={t('name_bn')} error={errors.name_bn}>
                                <TextInput value={data.name_bn} onChange={(e) => setData('name_bn', e.target.value)} />
                            </Field>
                            <Field label={t('active')}>
                                <SelectInput value={data.is_active ? '1' : '0'} onChange={(e) => setData('is_active', e.target.value === '1')}>
                                    <option value="1">{t('yes')}</option>
                                    <option value="0">{t('no')}</option>
                                </SelectInput>
                            </Field>
                            <div className="flex items-end gap-2">
                                <PrimaryButton type="submit" loading={processing}>{t('save')}</PrimaryButton>
                                <button type="button" className="rounded border border-slate-300 px-4 py-2 text-sm dark:border-slate-600" onClick={() => setShowForm(false)}>{t('cancel')}</button>
                            </div>
                        </form>
                        {!editing && <p className="mt-2 text-xs text-slate-500">{t('acc_opening_balance_hint')}</p>}
                    </Card>
                )}
                <Card className="p-0">
                    <ResponsiveTable head={[t('acc_ledger_code'), t('ledger'), t('ledger_group'), t('balance'), t('acc_control'), t('status'), '']}>
                        {ledgers.map((l) => (
                            <tr key={l.id} className="border-t border-slate-100 dark:border-slate-700">
                                <td className="px-3 py-2 font-medium">{l.code}</td>
                                <td className="px-3 py-2">{l.name}</td>
                                <td className="px-3 py-2">{t('acc_group_' + l.group.toLowerCase())}</td>
                                <td className="px-3 py-2 text-right">{Number(l.balance).toLocaleString()}</td>
                                <td className="px-3 py-2">{l.is_control ? t('acc_control') : '—'}</td>
                                <td className="px-3 py-2">{l.is_active ? t('active') : t('inactive')}</td>
                                <td className="px-3 py-2 text-right">
                                    <ActionButton fire={() => startEdit(l)} className="border border-slate-300 text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700">{t('edit')}</ActionButton>
                                </td>
                            </tr>
                        ))}
                    </ResponsiveTable>
                </Card>
            </div>
        </AppLayout>
    );
}
