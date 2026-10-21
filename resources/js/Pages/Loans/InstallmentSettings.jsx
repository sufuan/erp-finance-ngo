import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Manage Installment Settings (Step 15) — EMI option templates per product. */
export default function InstallmentSettings({ templates, products, frequencies, canOverride, filters }) {
    const [editing, setEditing] = useState(null);
    const form = useForm({ loan_product_id: products[0]?.id ?? '', frequency: frequencies[0]?.code ?? 'M', no_of_installments: '', profit_rate: '', comments: '', is_active: true });
    const pickProduct = (id) => {
        form.setData('loan_product_id', id);
        const product = products.find((p) => String(p.id) === String(id));
        if (product) form.setData((prev) => ({ ...prev, loan_product_id: id, profit_rate: product.profit_rate }));
    };
    const startEdit = (tpl) => { setEditing(tpl.id); form.setData({ loan_product_id: tpl.loan_product_id, frequency: tpl.frequency, no_of_installments: tpl.no_of_installments, profit_rate: tpl.profit_rate, comments: tpl.comments ?? '', is_active: tpl.is_active }); };
    const submit = (e) => {
        e.preventDefault();
        if (editing) form.put(`/loans/installment-settings/${editing}`, { preserveScroll: true, onSuccess: () => setEditing(null) });
        else form.post('/loans/installment-settings', { preserveScroll: true });
    };
    const remove = (id) => router.delete(`/loans/installment-settings/${id}`, { preserveScroll: true });
    const search = (e) => { e.preventDefault(); router.get('/loans/installment-settings', { search: e.target.elements.search.value }, { preserveState: true }); };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('manage_installment_settings')}</h1>
            <Card className="mb-4">
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label={`${t('account_type')} *`} error={form.errors.loan_product_id}>
                        <select className={inputCls} value={form.data.loan_product_id} onChange={(e) => pickProduct(e.target.value)} required disabled={!!editing}>
                            {products.map((p) => <option key={p.id} value={p.id}>{p.code} — {p.name_en}</option>)}
                        </select>
                    </Field>
                    <Field label={`${t('frequency')} *`} error={form.errors.frequency}>
                        <select className={inputCls} value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)} required>
                            {frequencies.map((f) => <option key={f.code} value={f.code}>{f.code} — {f.label_en} / {f.label_bn}</option>)}
                        </select>
                    </Field>
                    <Field label={`${t('no_of_installments')} *`} error={form.errors.no_of_installments}>
                        <input type="number" min="1" className={inputCls} value={form.data.no_of_installments} onChange={(e) => form.setData('no_of_installments', e.target.value)} required />
                    </Field>
                    <Field label={`${t('profit_rate')} *`} error={form.errors.profit_rate}>
                        <input type="number" step="0.01" min="0" className={inputCls} value={form.data.profit_rate} onChange={(e) => form.setData('profit_rate', e.target.value)} required />
                    </Field>
                    <Field label={t('comments')}>
                        <input className={inputCls} value={form.data.comments} onChange={(e) => form.setData('comments', e.target.value)} />
                    </Field>
                    <label className="flex min-h-[44px] items-center gap-2 text-sm">
                        <input type="checkbox" className="h-5 w-5" checked={!!form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                        {t('is_active')}
                    </label>
                    <div className="flex items-end gap-2">
                        <PrimaryButton type="submit" disabled={form.processing}>{editing ? t('save') : t('add')}</PrimaryButton>
                        {editing && <button type="button" onClick={() => setEditing(null)} className="min-h-[44px] rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">{t('cancel')}</button>}
                    </div>
                    {!canOverride && <p className="text-sm text-zinc-500 sm:col-span-2 lg:col-span-3">{t('rate_must_match_product')}</p>}
                </form>
            </Card>

            <Card className="mb-4">
                <form onSubmit={search} className="flex flex-wrap items-end gap-3">
                    <div className="min-w-56 flex-1">
                        <Field label={t('search')}>
                            <input name="search" className={inputCls} defaultValue={filters.search} />
                        </Field>
                    </div>
                    <PrimaryButton type="submit">{t('search')}</PrimaryButton>
                </form>
            </Card>

            <Card>
                <ResponsiveTable head={[t('account_type'), t('frequency'), t('no_of_installments'), t('profit_rate'), t('comments'), t('status'), t('actions')]}>
                    {templates.map((tpl) => (
                        <tr key={tpl.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{tpl.product}</td>
                            <td className="px-3 py-2">{tpl.frequency}</td>
                            <td className="px-3 py-2">{tpl.no_of_installments}</td>
                            <td className="px-3 py-2">{tpl.profit_rate}</td>
                            <td className="px-3 py-2">{tpl.comments ?? '—'}</td>
                            <td className="px-3 py-2">{tpl.is_active ? t('active') : t('inactive')}</td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                <button type="button" onClick={() => startEdit(tpl)} className="rounded-lg px-2 py-1 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/20">{t('edit')}</button>
                                <button type="button" onClick={() => remove(tpl.id)} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('delete')}</button>
                            </td>
                        </tr>
                    ))}
                    {templates.length === 0 && <tr><td className="px-3 py-3" colSpan={7}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
