import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Due Penalty Setup (Step 17) — per-loan penal charge. */
export default function DuePenaltySetup({ loans, products, samities, filters }) {
    const [charges, setCharges] = useState(() => Object.fromEntries(loans.map((l) => [l.id, l.penal_charge])));
    const search = (e) => {
        e.preventDefault();
        router.get('/loans/due-penalty', Object.fromEntries(new FormData(e.target).entries()), { preserveState: true });
    };
    const update = (id) => router.post(`/loans/due-penalty/${id}`, { penal_charge: charges[id] }, { preserveScroll: true });

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('due_penalty_setup')}</h1>
            <Card className="mb-4">
                <form onSubmit={search} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Field label={t('samity')}>
                        <select name="samity_id" className={inputCls} defaultValue={filters.samity_id ?? ''}>
                            <option value="">{t('all')}</option>
                            {samities.map((s) => <option key={s.id} value={s.id}>{s.samity_code} — {s.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('account_type')}>
                        <select name="loan_product_id" className={inputCls} defaultValue={filters.loan_product_id ?? ''}>
                            <option value="">{t('all')}</option>
                            {products.map((p) => <option key={p.id} value={p.id}>{p.code} — {p.name_en}</option>)}
                        </select>
                    </Field>
                    <Field label={t('member_no')}>
                        <input name="member" className={inputCls} defaultValue={filters.member ?? ''} />
                    </Field>
                    <Field label={t('loan_number')}>
                        <input name="account" className={inputCls} defaultValue={filters.account ?? ''} />
                    </Field>
                    <div className="flex items-end">
                        <PrimaryButton type="submit">{t('search')}</PrimaryButton>
                    </div>
                </form>
            </Card>
            <Card>
                <ResponsiveTable head={[t('sl'), t('member_no'), t('address'), t('loan_number'), t('opening_date'), t('loan_amount'), t('installment_amount'), t('due_amount'), t('penal_charge'), t('actions')]}>
                    {loans.map((l, i) => (
                        <tr key={l.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{i + 1}</td>
                            <td className="px-3 py-2">{l.member ?? '—'}</td>
                            <td className="px-3 py-2">{l.address ?? '—'}</td>
                            <td className="px-3 py-2">{l.loan_number}</td>
                            <td className="px-3 py-2">{l.opening_date}</td>
                            <td className="px-3 py-2">{l.loan_amount}</td>
                            <td className="px-3 py-2">{l.installment_amount}</td>
                            <td className="px-3 py-2">{l.due_amount}</td>
                            <td className="px-3 py-2">
                                <input type="number" step="0.01" min="0" className="min-h-[44px] w-28 rounded-lg border border-zinc-300 px-2 dark:border-zinc-700 dark:bg-zinc-900"
                                    value={charges[l.id] ?? ''} onChange={(e) => setCharges((prev) => ({ ...prev, [l.id]: e.target.value }))} />
                            </td>
                            <td className="px-3 py-2">
                                <button type="button" onClick={() => update(l.id)} className="rounded-lg px-2 py-1 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/20">{t('update_penal_charge')}</button>
                            </td>
                        </tr>
                    ))}
                    {loans.length === 0 && <tr><td className="px-3 py-3" colSpan={10}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
