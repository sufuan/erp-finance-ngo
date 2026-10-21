import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

const EMPTY = {
    code: '', name_en: '', name_bn: '', profit_rate: '', rate_method: 'declining',
    min_amount: '', max_amount: '', min_term: 1, max_term: 100, allowed_frequencies: ['D', 'W', 'M'],
    grace_days: 0, penal_rate: 0, gl_mapping_prefix: 'loan.general', min_cycle: 0, max_cycle: '',
    min_savings_balance: 0, max_concurrent_loans: 1, min_member_months: 0, fees: '', is_active: true,
};

/** Loan products master (Step 15). profit_rate here is THE rate. */
export default function LoanProducts({ products, frequencies, defaultRate, cap }) {
    const [editing, setEditing] = useState(null);
    const form = useForm(EMPTY);
    const startEdit = (p) => { setEditing(p.id); form.setData({ ...EMPTY, ...p, max_cycle: p.max_cycle ?? '' }); };
    const startNew = () => { setEditing('new'); form.setData({ ...EMPTY, profit_rate: defaultRate }); };
    const submit = (e) => {
        e.preventDefault();
        if (editing === 'new') form.post('/loans/products', { preserveScroll: true, onSuccess: () => setEditing(null) });
        else form.put(`/loans/products/${editing}`, { preserveScroll: true, onSuccess: () => setEditing(null) });
    };
    const remove = (id) => router.delete(`/loans/products/${id}`, { preserveScroll: true });
    const toggleFreq = (code) => {
        const current = form.data.allowed_frequencies ?? [];
        form.setData('allowed_frequencies', current.includes(code) ? current.filter((c) => c !== code) : [...current, code]);
    };

    return (
        <AppLayout>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold">{t('loan_products')}</h1>
                <PrimaryButton type="button" onClick={startNew}>{t('add_product')}</PrimaryButton>
            </div>
            <p className="mb-4 text-sm text-zinc-500">{t('mra_cap_note')} {cap}%</p>

            {editing !== null && (
                <Card className="mb-4">
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Field label={`${t('code')} *`} error={form.errors.code}>
                            <input className={inputCls} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} required />
                        </Field>
                        <Field label={`${t('name_en')} *`} error={form.errors.name_en}>
                            <input className={inputCls} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} required />
                        </Field>
                        <Field label={t('name_bn')}>
                            <input className={inputCls} value={form.data.name_bn ?? ''} onChange={(e) => form.setData('name_bn', e.target.value)} />
                        </Field>
                        <Field label={`${t('profit_rate')} *`} error={form.errors.profit_rate}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={form.data.profit_rate} onChange={(e) => form.setData('profit_rate', e.target.value)} required />
                        </Field>
                        <Field label={t('rate_method')}>
                            <select className={inputCls} value={form.data.rate_method} onChange={(e) => form.setData('rate_method', e.target.value)}>
                                <option value="declining">{t('declining')}</option>
                                <option value="flat">{t('flat')}</option>
                            </select>
                        </Field>
                        <Field label={t('gl_mapping_prefix')}>
                            <input className={inputCls} value={form.data.gl_mapping_prefix} onChange={(e) => form.setData('gl_mapping_prefix', e.target.value)} />
                        </Field>
                        <Field label={t('min_amount')}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={form.data.min_amount} onChange={(e) => form.setData('min_amount', e.target.value)} />
                        </Field>
                        <Field label={t('max_amount')}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={form.data.max_amount} onChange={(e) => form.setData('max_amount', e.target.value)} />
                        </Field>
                        <Field label={t('min_term')}>
                            <input type="number" min="1" className={inputCls} value={form.data.min_term} onChange={(e) => form.setData('min_term', e.target.value)} />
                        </Field>
                        <Field label={t('max_term')}>
                            <input type="number" min="1" className={inputCls} value={form.data.max_term} onChange={(e) => form.setData('max_term', e.target.value)} />
                        </Field>
                        <Field label={t('grace_days')}>
                            <input type="number" min="0" className={inputCls} value={form.data.grace_days} onChange={(e) => form.setData('grace_days', e.target.value)} />
                        </Field>
                        <Field label={t('penal_rate')}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={form.data.penal_rate} onChange={(e) => form.setData('penal_rate', e.target.value)} />
                        </Field>
                        <Field label={t('min_cycle')}>
                            <input type="number" min="0" className={inputCls} value={form.data.min_cycle} onChange={(e) => form.setData('min_cycle', e.target.value)} />
                        </Field>
                        <Field label={t('max_cycle')}>
                            <input type="number" min="0" className={inputCls} value={form.data.max_cycle} onChange={(e) => form.setData('max_cycle', e.target.value)} />
                        </Field>
                        <Field label={t('min_savings_balance')}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={form.data.min_savings_balance} onChange={(e) => form.setData('min_savings_balance', e.target.value)} />
                        </Field>
                        <Field label={t('max_concurrent_loans')}>
                            <input type="number" min="1" className={inputCls} value={form.data.max_concurrent_loans} onChange={(e) => form.setData('max_concurrent_loans', e.target.value)} />
                        </Field>
                        <Field label={t('min_member_months')}>
                            <input type="number" min="0" className={inputCls} value={form.data.min_member_months} onChange={(e) => form.setData('min_member_months', e.target.value)} />
                        </Field>
                        <Field label={t('fees')}>
                            <input className={inputCls} value={form.data.fees} onChange={(e) => form.setData('fees', e.target.value)} />
                        </Field>
                        <div className="sm:col-span-2 lg:col-span-3">
                            <span className="mb-1 block text-sm font-medium">{t('allowed_frequencies')}</span>
                            <div className="flex flex-wrap gap-3">
                                {frequencies.map((f) => (
                                    <label key={f.code} className="flex min-h-[44px] items-center gap-2 text-sm">
                                        <input type="checkbox" className="h-5 w-5" checked={(form.data.allowed_frequencies ?? []).includes(f.code)} onChange={() => toggleFreq(f.code)} />
                                        {f.code} — {f.label_en}
                                    </label>
                                ))}
                            </div>
                        </div>
                        <label className="flex min-h-[44px] items-center gap-2 text-sm">
                            <input type="checkbox" className="h-5 w-5" checked={!!form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                            {t('is_active')}
                        </label>
                        <div className="flex items-end gap-2">
                            <PrimaryButton type="submit" disabled={form.processing}>{t('save')}</PrimaryButton>
                            <button type="button" onClick={() => setEditing(null)} className="min-h-[44px] rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">{t('cancel')}</button>
                        </div>
                    </form>
                </Card>
            )}

            <Card>
                <ResponsiveTable head={[t('code'), t('name_en'), t('profit_rate'), t('rate_method'), t('effective_rate'), t('min_amount'), t('max_amount'), t('allowed_frequencies'), t('status'), t('actions')]}>
                    {products.map((p) => (
                        <tr key={p.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{p.code}</td>
                            <td className="px-3 py-2">{p.name_en}</td>
                            <td className="px-3 py-2">{p.profit_rate}</td>
                            <td className="px-3 py-2">{t(p.rate_method)}</td>
                            <td className="px-3 py-2">{p.effective_rate}%</td>
                            <td className="px-3 py-2">{p.min_amount}</td>
                            <td className="px-3 py-2">{p.max_amount}</td>
                            <td className="px-3 py-2">{(p.allowed_frequencies ?? []).join(', ')}</td>
                            <td className="px-3 py-2">{p.is_active ? t('active') : t('inactive')}</td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                <button type="button" onClick={() => startEdit(p)} className="rounded-lg px-2 py-1 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/20">{t('edit')}</button>
                                <button type="button" onClick={() => remove(p.id)} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('delete')}</button>
                            </td>
                        </tr>
                    ))}
                    {products.length === 0 && <tr><td className="px-3 py-3" colSpan={10}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
