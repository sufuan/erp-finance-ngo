import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../Layouts/AppLayout';
import { Card, Field, PrimaryButton, ResponsiveTable, SelectInput, TextInput } from '../../../Components/ui';
import { t } from '../../../lib/i18n';

const empty = {
    code: '', name: '', type: 'Branch', parent_branch_id: '', opening_date: '',
    country: '', division: '', district: '', upazila: '', union: '',
    manager_phone: '', address: '', latitude: '', longitude: '', status: 'Active',
};

export default function Index({ branches, parents, types }) {
    const [editing, setEditing] = useState(null);
    const form = useForm(empty);

    const startEdit = (branch) => {
        setEditing(branch.id);
        form.setData({
            code: branch.code, name: branch.name, type: branch.type,
            parent_branch_id: branch.parent?.id ?? '', opening_date: branch.opening_date ?? '',
            country: branch.country ?? '', division: branch.division ?? '', district: branch.district ?? '',
            upazila: branch.upazila ?? '', union: branch.union ?? '', manager_phone: branch.manager_phone ?? '',
            address: branch.address ?? '', latitude: '', longitude: '', status: branch.status,
        });
    };

    const reset = () => {
        setEditing(null);
        form.reset();
        form.setData(empty);
    };

    const submit = (e) => {
        e.preventDefault();
        if (editing) {
            form.put(`/settings/branches/${editing}`, { onSuccess: reset });
        } else {
            form.post('/settings/branches', { onSuccess: reset });
        }
    };

    return (
        <AppLayout>
            <Head title={t('branches')} />
            <div className="space-y-4">
                <Card>
                    <h1 className="mb-4 text-xl font-semibold">{t('branches')}</h1>
                    <ResponsiveTable head={[t('branch_code'), t('branch_name'), t('branch_type'), t('parent_branch'), t('status'), t('actions')]}>
                        {branches.data.map((b) => (
                            <tr key={b.id} className="border-b border-zinc-100 dark:border-zinc-800">
                                <td className="px-3 py-2">{b.code}</td>
                                <td className="px-3 py-2">{b.name}</td>
                                <td className="px-3 py-2">{b.type}</td>
                                <td className="px-3 py-2">{b.parent ? `${b.parent.code} — ${b.parent.name}` : '—'}</td>
                                <td className="px-3 py-2">{b.status === 'Active' ? t('active') : t('inactive')}</td>
                                <td className="px-3 py-2">
                                    <button type="button" onClick={() => startEdit(b)} className="min-h-[44px] font-medium text-emerald-700 hover:underline dark:text-emerald-400">
                                        {t('edit')}
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </ResponsiveTable>
                </Card>

                <Card>
                    <h2 className="mb-4 text-lg font-semibold">{editing ? t('edit_branch') : t('add_branch')}</h2>
                    <form onSubmit={submit} className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                        <Field label={t('branch_code')} required error={form.errors.code}>
                            <TextInput value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} required />
                        </Field>
                        <Field label={t('branch_name')} required error={form.errors.name}>
                            <TextInput value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        </Field>
                        <Field label={t('branch_type')} required error={form.errors.type}>
                            <SelectInput value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                                {types.map((type) => (
                                    <option key={type} value={type}>{type}</option>
                                ))}
                            </SelectInput>
                        </Field>
                        <Field label={t('parent_branch')} error={form.errors.parent_branch_id}>
                            <SelectInput value={form.data.parent_branch_id} onChange={(e) => form.setData('parent_branch_id', e.target.value)}>
                                <option value="">—</option>
                                {parents.map((p) => (
                                    <option key={p.id} value={p.id}>{p.code} — {p.name}</option>
                                ))}
                            </SelectInput>
                        </Field>
                        <Field label={t('opening_date')} error={form.errors.opening_date}>
                            <TextInput type="date" value={form.data.opening_date} onChange={(e) => form.setData('opening_date', e.target.value)} />
                        </Field>
                        <Field label={t('manager_phone')} error={form.errors.manager_phone}>
                            <TextInput value={form.data.manager_phone} onChange={(e) => form.setData('manager_phone', e.target.value)} />
                        </Field>
                        <Field label={t('country')} error={form.errors.country}>
                            <TextInput value={form.data.country} onChange={(e) => form.setData('country', e.target.value)} />
                        </Field>
                        <Field label={t('division')} error={form.errors.division}>
                            <TextInput value={form.data.division} onChange={(e) => form.setData('division', e.target.value)} />
                        </Field>
                        <Field label={t('district')} error={form.errors.district}>
                            <TextInput value={form.data.district} onChange={(e) => form.setData('district', e.target.value)} />
                        </Field>
                        <Field label={t('upazila')} error={form.errors.upazila}>
                            <TextInput value={form.data.upazila} onChange={(e) => form.setData('upazila', e.target.value)} />
                        </Field>
                        <Field label={t('union')} error={form.errors.union}>
                            <TextInput value={form.data.union} onChange={(e) => form.setData('union', e.target.value)} />
                        </Field>
                        <Field label={t('status')} required error={form.errors.status}>
                            <SelectInput value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                <option value="Active">{t('active')}</option>
                                <option value="Inactive">{t('inactive')}</option>
                            </SelectInput>
                        </Field>
                        <div className="md:col-span-2 lg:col-span-3">
                            <Field label={t('address')} error={form.errors.address}>
                                <TextInput value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                            </Field>
                        </div>
                        <div className="flex items-end gap-2">
                            <PrimaryButton type="submit" disabled={form.processing}>{t('save')}</PrimaryButton>
                            {editing && (
                                <button type="button" onClick={reset} className="min-h-[44px] rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">
                                    {t('cancel')}
                                </button>
                            )}
                        </div>
                    </form>
                </Card>
            </div>
        </AppLayout>
    );
}
