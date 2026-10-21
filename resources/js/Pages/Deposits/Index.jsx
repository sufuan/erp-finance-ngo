import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Deposit account list (Step 10) — filters + the reference's 15 columns. */
export default function DepositIndex({ accounts, filters, products }) {
    const [f, setF] = useState({
        deposit_product_id: filters.deposit_product_id ?? '', member_id: filters.member_id ?? '',
        from: filters.from ?? '', upto: filters.upto ?? '', phone: filters.phone ?? '', account: filters.account ?? '',
    });
    const set = (k, v) => setF((prev) => ({ ...prev, [k]: v }));
    const apply = (e) => { e.preventDefault(); router.get('/deposits', f, { preserveState: true }); };
    const rows = accounts.data ?? [];

    return (
        <AppLayout>
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <h1 className="text-xl font-semibold">{t('account_list')}</h1>
                <Link href="/deposits/opening" className="ml-auto min-h-[44px] rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">{t('deposit_account_opening')}</Link>
                <Link href="/deposits/other" className="min-h-[44px] rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700">{t('other_account_opening')}</Link>
                <Link href="/deposits/products" className="min-h-[44px] rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700">{t('deposit_products')}</Link>
            </div>

            <Card className="mb-4">
                <form onSubmit={apply} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label={t('account_type')}>
                        <select className={inputCls} value={f.deposit_product_id} onChange={(e) => set('deposit_product_id', e.target.value)}>
                            <option value="">—</option>
                            {products.map((p) => <option key={p.id} value={p.id}>{p.code} — {p.name_en}</option>)}
                        </select>
                    </Field>
                    <Field label={t('member_no')}>
                        <input className={inputCls} value={f.member_id} onChange={(e) => set('member_id', e.target.value)} />
                    </Field>
                    <Field label={t('phone_number')}>
                        <input className={inputCls} value={f.phone} onChange={(e) => set('phone', e.target.value)} />
                    </Field>
                    <Field label={t('select_account')}>
                        <input className={inputCls} value={f.account} onChange={(e) => set('account', e.target.value)} />
                    </Field>
                    <Field label={`${t('from_date')} *`}>
                        <input type="date" className={inputCls} value={f.from} onChange={(e) => set('from', e.target.value)} />
                    </Field>
                    <Field label={`${t('upto_date')} *`}>
                        <input type="date" className={inputCls} value={f.upto} onChange={(e) => set('upto', e.target.value)} />
                    </Field>
                    <div className="flex items-end">
                        <PrimaryButton type="submit">{t('search')}</PrimaryButton>
                    </div>
                </form>
            </Card>

            <div className="hidden overflow-x-auto rounded-2xl bg-white shadow md:block dark:bg-zinc-900">
                <table className="w-full min-w-[1300px] text-left text-sm">
                    <thead>
                        <tr className="border-b border-zinc-200 dark:border-zinc-800">
                            {[t('branch'), t('phone_number'), t('member_no'), t('account_type'), t('account_number'), t('full_name'), t('account_address'), t('total_credit'), t('total_debit'), t('current_balance'), t('credit_limit'), t('active'), t('opening_date'), t('closing_date'), t('actions')].map((h, i) => <th key={i} className="whitespace-nowrap px-3 py-3">{h}</th>)}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((a) => (
                            <tr key={a.id} className="border-b border-zinc-100 dark:border-zinc-800">
                                <td className="px-3 py-2">{a.branch_code ?? '—'}</td>
                                <td className="px-3 py-2">{a.phone ?? '—'}</td>
                                <td className="px-3 py-2">{a.member_no ?? '—'}</td>
                                <td className="px-3 py-2">{a.product ?? '—'}</td>
                                <td className="px-3 py-2">{a.account_number}</td>
                                <td className="px-3 py-2">{a.name}</td>
                                <td className="px-3 py-2">{a.address ?? '—'}</td>
                                <td className="px-3 py-2">{a.total_credit}</td>
                                <td className="px-3 py-2">{a.total_debit}</td>
                                <td className="px-3 py-2">{a.current_balance}</td>
                                <td className="px-3 py-2">{a.credit_limit}</td>
                                <td className="px-3 py-2">{a.is_active ? t('yes') : t('no')}</td>
                                <td className="px-3 py-2">{a.opening_date}</td>
                                <td className="px-3 py-2">{a.closing_date ?? '—'}</td>
                                <td className="px-3 py-2">—</td>
                            </tr>
                        ))}
                        {rows.length === 0 && <tr><td className="px-3 py-3" colSpan={15}>{t('no_data_found')}</td></tr>}
                    </tbody>
                </table>
            </div>

            <div className="space-y-3 md:hidden">
                {rows.map((a) => (
                    <Card key={a.id}>
                        <div className="font-medium">{a.name} · {a.product}</div>
                        <div className="text-sm text-zinc-500">{a.account_number} · {a.member_no}</div>
                        <div className="text-sm">{t('current_balance')}: {a.current_balance}</div>
                    </Card>
                ))}
                {rows.length === 0 && <Card>{t('no_data_found')}</Card>}
            </div>
        </AppLayout>
    );
}
