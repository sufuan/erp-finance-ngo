import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, SelectInput, TextInput } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

export default function CashierDetails({ rows = [], from = '', to = '', userId = null, users = [] }) {
    const [f, setF] = useState({ user_id: userId ? String(userId) : '', from, to });
    const apply = (e) => {
        e.preventDefault();
        router.get('/accounts/cashier-details', f, { preserveState: true });
    };

    return (
        <AppLayout>
            <div className="mx-auto max-w-5xl space-y-4 p-4">
                <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t('acc_cashier_transaction_details')}</h1>
                <Card className="p-4">
                    <form onSubmit={apply} className="grid gap-3 sm:grid-cols-4">
                        <Field label={t('cashier')}>
                            <SelectInput value={f.user_id} onChange={(e) => setF({ ...f, user_id: e.target.value })}>
                                <option value="">{t('all')}</option>
                                {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </SelectInput>
                        </Field>
                        <Field label={t('from_date')}><TextInput type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} /></Field>
                        <Field label={t('to_date')}><TextInput type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} /></Field>
                        <div className="flex items-end"><PrimaryButton type="submit">{t('acc_run_report')}</PrimaryButton></div>
                    </form>
                </Card>
                <Card className="p-0">
                    <ResponsiveTable head={[t('date'), t('cashier'), t('tran_code'), t('acc_voucher_no'), t('amount'), t('balance'), t('status')]}>
                        {rows.map((r, i) => (
                            <tr key={i} className="border-t border-slate-100 dark:border-slate-700">
                                <td className="px-3 py-2">{r.date}</td>
                                <td className="px-3 py-2">{r.user}</td>
                                <td className="px-3 py-2">{r.tran_code}</td>
                                <td className="px-3 py-2">{r.voucher_no}</td>
                                <td className="px-3 py-2 text-right">{Number(r.amount).toLocaleString()}</td>
                                <td className="px-3 py-2 text-right">{Number(r.balance_after).toLocaleString()}</td>
                                <td className="px-3 py-2">{r.cancelled ? t('acc_status_cancelled') : t('active')}</td>
                            </tr>
                        ))}
                        {rows.length === 0 && (
                            <tr><td colSpan={7} className="px-3 py-6 text-center text-slate-500">{t('no_data')}</td></tr>
                        )}
                    </ResponsiveTable>
                </Card>
            </div>
        </AppLayout>
    );
}
