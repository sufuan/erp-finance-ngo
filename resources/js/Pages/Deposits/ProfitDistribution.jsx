import { Link, useForm } from '@inertiajs/react';
import { Card, Field, PrimaryButton, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

const BASES = ['D', 'B', 'M', 'X', 'A', 'O', 'N'];

/** Manual + automatic profit distribution calculator (Step 13). */
export default function ProfitDistribution({ auto, samities, products, businessDate }) {
    const form = useForm({
        samity_id: samities[0]?.id ?? '', account_type: 'ALL', profit_basis: 'A',
        from_date: '', to_date: '', profit_rate: '15', posting_date: businessDate ?? '',
        exclude_zero_balance: true, auto_rates: auto,
    });
    const submit = (e) => { e.preventDefault(); form.post('/deposits/profit/distributions'); };

    return (
        <AppLayout>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold">{t(auto ? 'profit_distribution_auto' : 'manual_profit_distribution')}</h1>
                <div className="flex gap-2">
                    <Link href="/deposits/profit/distribution" className={`min-h-[44px] rounded-lg px-3 py-2 text-sm ${!auto ? 'bg-emerald-600 text-white' : 'border border-zinc-300 dark:border-zinc-700'}`}>{t('manual_profit_distribution')}</Link>
                    <Link href="/deposits/profit/distribution/auto" className={`min-h-[44px] rounded-lg px-3 py-2 text-sm ${auto ? 'bg-emerald-600 text-white' : 'border border-zinc-300 dark:border-zinc-700'}`}>{t('profit_distribution_auto')}</Link>
                    <Link href="/deposits/profit/distributions" className="min-h-[44px] rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700">{t('profit_distribution_list')}</Link>
                </div>
            </div>
            <Card>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label={t('samity')} required error={form.errors.samity_id}>
                        <select className={inputCls} value={form.data.samity_id} onChange={(e) => form.setData('samity_id', e.target.value)} required>
                            {samities.map((s) => <option key={s.id} value={s.id}>{s.samity_code} — {s.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('account_type')}>
                        <select className={inputCls} value={form.data.account_type} onChange={(e) => form.setData('account_type', e.target.value)}>
                            <option value="ALL">{t('all')}</option>
                            {products.map((p) => <option key={p.id} value={p.code}>{p.code}</option>)}
                        </select>
                    </Field>
                    <Field label={t('profit_basis')} error={form.errors.profit_basis}>
                        <select className={inputCls} value={form.data.profit_basis} onChange={(e) => form.setData('profit_basis', e.target.value)}>
                            {BASES.map((b) => <option key={b} value={b}>{b} — {t(`basis_${b.toLowerCase()}`)}</option>)}
                        </select>
                    </Field>
                    <Field label={t('from_date')} required error={form.errors.from_date}>
                        <input type="date" className={inputCls} value={form.data.from_date} onChange={(e) => form.setData('from_date', e.target.value)} required />
                    </Field>
                    <Field label={t('upto_date')} required error={form.errors.to_date}>
                        <input type="date" className={inputCls} value={form.data.to_date} onChange={(e) => form.setData('to_date', e.target.value)} required />
                    </Field>
                    {!auto && (
                        <Field label={t('profit_rate')} required error={form.errors.profit_rate}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={form.data.profit_rate} onChange={(e) => form.setData('profit_rate', e.target.value)} required />
                        </Field>
                    )}
                    <Field label={t('posting_date')} required error={form.errors.posting_date}>
                        <input type="date" className={inputCls} value={form.data.posting_date} onChange={(e) => form.setData('posting_date', e.target.value)} required />
                    </Field>
                    <Field label={t('exclude_zero_balance')} required>
                        <select className={inputCls} value={form.data.exclude_zero_balance ? '1' : '0'} onChange={(e) => form.setData('exclude_zero_balance', e.target.value === '1')}>
                            <option value="1">{t('yes')}</option>
                            <option value="0">{t('no')}</option>
                        </select>
                    </Field>
                    <div className="flex items-end">
                        <PrimaryButton type="submit" loading={form.processing}>{t('calculate_profit')}</PrimaryButton>
                    </div>
                </form>
            </Card>
        </AppLayout>
    );
}
