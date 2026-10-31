import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { ActionButton, Card, Field, PrimaryButton, ResponsiveTable, SelectInput, TextInput } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

export default function Masters({ entity, rows = [], ledgers = [], billingTypes = [] }) {
    const isBilling = entity === 'billing-types';
    const [editing, setEditing] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        name_en: '', name_bn: '', name: '', billing_type_id: '', ledger_id: '', side: 'Receive', is_active: true,
    });

    const startEdit = (item) => {
        setEditing(item);
        setShowForm(true);
        if (isBilling) {
            setData({ name_en: item.name_en, name_bn: item.name_bn ?? '', name: '', billing_type_id: '', ledger_id: '', side: 'Receive', is_active: item.is_active });
        } else {
            setData({ name_en: '', name_bn: '', name: item.name, billing_type_id: item.billing_type_id ?? '', ledger_id: item.ledger_id ?? '', side: item.side, is_active: item.is_active });
        }
    };
    const startNew = () => { setEditing(null); setShowForm(true); reset(); };
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => { setShowForm(false); setEditing(null); reset(); } };
        if (editing) put(`/accounts/masters/${entity}/${editing.id}`, opts);
        else post(`/accounts/masters/${entity}`, opts);
    };
    const deactivate = (item) => router.delete(`/accounts/masters/${entity}/${item.id}`, { preserveScroll: true });

    return (
        <AppLayout>
            <div className="mx-auto max-w-5xl space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t(isBilling ? 'acc_billing_type' : 'acc_receive_payment_setup')}</h1>
                    <PrimaryButton onClick={startNew}>{t('add_new')}</PrimaryButton>
                </div>
                {showForm && (
                    <Card className="p-4">
                        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-3">
                            {isBilling ? (
                                <>
                                    <Field label={t('name_en')} error={errors.name_en} required>
                                        <TextInput value={data.name_en} onChange={(e) => setData('name_en', e.target.value)} required />
                                    </Field>
                                    <Field label={t('name_bn')} error={errors.name_bn}>
                                        <TextInput value={data.name_bn} onChange={(e) => setData('name_bn', e.target.value)} />
                                    </Field>
                                </>
                            ) : (
                                <>
                                    <Field label={t('name')} error={errors.name} required>
                                        <TextInput value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                                    </Field>
                                    <Field label={t('acc_billing_type')} error={errors.billing_type_id}>
                                        <SelectInput value={data.billing_type_id} onChange={(e) => setData('billing_type_id', e.target.value)}>
                                            <option value="">{t('select')}</option>
                                            {billingTypes.map((b) => <option key={b.id} value={b.id}>{b.name_en}</option>)}
                                        </SelectInput>
                                    </Field>
                                    <Field label={t('acc_mode')} error={errors.side} required>
                                        <SelectInput value={data.side} onChange={(e) => setData('side', e.target.value)}>
                                            <option value="Receive">{t('receive')}</option>
                                            <option value="Payment">{t('payment')}</option>
                                        </SelectInput>
                                    </Field>
                                    <Field label={t('ledger')} error={errors.ledger_id} required>
                                        <SelectInput value={data.ledger_id} onChange={(e) => setData('ledger_id', e.target.value)}>
                                            <option value="">{t('select')}</option>
                                            {ledgers.map((l) => <option key={l.id} value={l.id}>{l.code} — {l.name}</option>)}
                                        </SelectInput>
                                    </Field>
                                </>
                            )}
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
                    </Card>
                )}
                <Card className="p-0">
                    <ResponsiveTable head={isBilling
                        ? [t('name_en'), t('name_bn'), t('status'), '']
                        : [t('name'), t('acc_billing_type'), t('acc_mode'), t('ledger'), t('status'), '']}>
                        {rows.map((item) => (
                            <tr key={item.id} className="border-t border-slate-100 dark:border-slate-700">
                                {isBilling ? (
                                    <>
                                        <td className="px-3 py-2 font-medium">{item.name_en}</td>
                                        <td className="px-3 py-2">{item.name_bn}</td>
                                    </>
                                ) : (
                                    <>
                                        <td className="px-3 py-2 font-medium">{item.name}</td>
                                        <td className="px-3 py-2">{item.billing_type ?? '—'}</td>
                                        <td className="px-3 py-2">{item.side === 'Receive' ? t('receive') : t('payment')}</td>
                                        <td className="px-3 py-2">{item.ledger}</td>
                                    </>
                                )}
                                <td className="px-3 py-2">{item.is_active ? t('active') : t('inactive')}</td>
                                <td className="px-3 py-2">
                                    <div className="flex justify-end gap-2">
                                        <ActionButton fire={() => startEdit(item)} className="border border-slate-300 text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700">{t('edit')}</ActionButton>
                                        {item.is_active && (
                                            <ActionButton fire={() => deactivate(item)} className="bg-rose-600 text-white hover:bg-rose-700">{t('deactivate')}</ActionButton>
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {rows.length === 0 && (
                            <tr><td colSpan={6} className="px-3 py-6 text-center text-slate-500">{t('no_data')}</td></tr>
                        )}
                    </ResponsiveTable>
                </Card>
            </div>
        </AppLayout>
    );
}
