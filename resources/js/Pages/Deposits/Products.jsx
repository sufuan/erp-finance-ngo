import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

const empty = {
    code: '', name_en: '', name_bn: '', is_compulsory: false, default_profit_rate: '', minimum_balance: '',
    minimum_opening_amount: '', maturity_months: '', premature_penalty_rate: '', auto_renew: false,
    gl_mapping_prefix: 'deposit.savings', config: '', withdrawal_rules: '', is_active: true,
};

/** Deposit product master (Step 10) — everything is editable data. */
export default function DepositProducts({ products }) {
    const [editing, setEditing] = useState(null);
    const [saving, setSaving] = useState(false);
    const form = useForm(empty);

    const startEdit = (p) => {
        setEditing(p.id);
        form.setData({
            ...empty, ...p,
            config: p.config ? JSON.stringify(p.config, null, 2) : '',
            withdrawal_rules: p.withdrawal_rules ? JSON.stringify(p.withdrawal_rules, null, 2) : '',
        });
    };
    const startNew = () => { setEditing('new'); form.setData(empty); };
    const cancel = () => { setEditing(null); form.reset(); };

    const submit = (e) => {
        e.preventDefault();
        let config = null;
        let rules = null;
        try { config = form.data.config ? JSON.parse(form.data.config) : null; } catch { form.setError('config', 'Invalid JSON'); return; }
        try { rules = form.data.withdrawal_rules ? JSON.parse(form.data.withdrawal_rules) : null; } catch { form.setError('withdrawal_rules', 'Invalid JSON'); return; }
        const payload = { ...form.data, config, withdrawal_rules: rules };
        // Both paths submit via router.* (not form.post), so the flight is
        // tracked through callbacks — form.processing never fires here.
        const opts = {
            preserveScroll: true, onSuccess: cancel,
            onStart: () => setSaving(true), onFinish: () => setSaving(false),
        };
        if (editing === 'new') router.post('/deposits/products', payload, opts);
        else router.put(`/deposits/products/${editing}`, payload, opts);
    };

    const text = (key, label, type = 'text') => (
        <Field label={t(label)} error={form.errors[key]}>
            <input type={type} className={inputCls} value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} />
        </Field>
    );
    const check = (key, label) => (
        <label className="flex min-h-[44px] items-center gap-2 text-sm">
            <input type="checkbox" className="h-5 w-5" checked={!!form.data[key]} onChange={(e) => form.setData(key, e.target.checked)} />
            {t(label)}
        </label>
    );

    return (
        <AppLayout>
            <div className="mb-4 flex items-center gap-2">
                <h1 className="text-xl font-semibold">{t('deposit_products')}</h1>
                <button type="button" onClick={startNew} className="ml-auto min-h-[44px] rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">{t('add')}</button>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                {products.map((p) => (
                    <Card key={p.id}>
                        <div className="flex flex-wrap items-start gap-2">
                            <div>
                                <div className="font-semibold">{p.code} — {p.name_en}</div>
                                <div className="text-sm text-zinc-500">{p.name_bn ?? ''}</div>
                            </div>
                            <div className="ml-auto flex gap-2">
                                <button type="button" onClick={() => startEdit(p)} className="min-h-[44px] rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700">{t('edit')}</button>
                                <button type="button" onClick={() => router.delete(`/deposits/products/${p.id}`, { preserveScroll: true })} className="min-h-[44px] rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('delete')}</button>
                            </div>
                        </div>
                        <dl className="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
                            <div><dt className="text-zinc-500">{t('profit_rate')}</dt><dd>{p.default_profit_rate}%</dd></div>
                            <div><dt className="text-zinc-500">{t('minimum_balance')}</dt><dd>{p.minimum_balance}</dd></div>
                            <div><dt className="text-zinc-500">{t('maturity_months')}</dt><dd>{p.maturity_months ?? '—'}</dd></div>
                            <div><dt className="text-zinc-500">{t('premature_penalty_rate')}</dt><dd>{p.premature_penalty_rate}%</dd></div>
                            <div><dt className="text-zinc-500">{t('gl_mapping_prefix')}</dt><dd>{p.gl_mapping_prefix}</dd></div>
                            <div><dt className="text-zinc-500">{t('active')}</dt><dd>{p.is_active ? t('yes') : t('no')}</dd></div>
                        </dl>
                    </Card>
                ))}
            </div>

            {editing !== null && (
                <Card className="mt-4">
                    <h2 className="mb-3 font-medium">{editing === 'new' ? t('add') : t('edit')}</h2>
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {text('code', 'product_code')}
                        {text('name_en', 'name_en')}
                        {text('name_bn', 'name_bn')}
                        {text('default_profit_rate', 'profit_rate', 'number')}
                        {text('minimum_balance', 'minimum_balance', 'number')}
                        {text('minimum_opening_amount', 'minimum_opening_amount', 'number')}
                        {text('maturity_months', 'maturity_months', 'number')}
                        {text('premature_penalty_rate', 'premature_penalty_rate', 'number')}
                        {text('gl_mapping_prefix', 'gl_mapping_prefix')}
                        {check('is_compulsory', 'is_compulsory')}
                        {check('auto_renew', 'auto_renew')}
                        {check('is_active', 'active')}
                        <div className="sm:col-span-2 lg:col-span-3">
                            <Field label={t('config_json')} error={form.errors.config}>
                                <textarea className={`${inputCls} font-mono`} rows={8} value={form.data.config} onChange={(e) => form.setData('config', e.target.value)} />
                            </Field>
                        </div>
                        <div className="sm:col-span-2 lg:col-span-3">
                            <Field label={t('withdrawal_rules')} error={form.errors.withdrawal_rules}>
                                <textarea className={`${inputCls} font-mono`} rows={3} value={form.data.withdrawal_rules} onChange={(e) => form.setData('withdrawal_rules', e.target.value)} />
                            </Field>
                        </div>
                        <div className="flex gap-2 sm:col-span-2 lg:col-span-3">
                            <PrimaryButton type="submit" loading={saving}>{t('save')}</PrimaryButton>
                            <button type="button" onClick={cancel} className="min-h-[44px] rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">{t('cancel')}</button>
                        </div>
                    </form>
                </Card>
            )}
        </AppLayout>
    );
}
