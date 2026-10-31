import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, SelectInput, TextInput } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

const STATUS_TONES = {
    Authorized: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    Pending: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    Cancelled: 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300',
};

export default function Vouchers({ preset = 'all', filters = {}, vouchers = [], branches = [] }) {
    const [f, setF] = useState({
        from: filters.from ?? '', to: filters.to ?? '', status: filters.status ?? '',
        voucher_type: filters.voucher_type ?? '', q: filters.q ?? '', branch_id: filters.branch_id ?? '',
    });
    const title = preset === 'cancelled' ? 'acc_transaction_cancel_list' : preset === 'backdate' ? 'acc_back_date_list' : 'acc_voucher_list';

    const apply = (e) => {
        e.preventDefault();
        router.get('/accounts/vouchers', { preset, ...f }, { preserveState: true });
    };

    return (
        <AppLayout>
            <div className="mx-auto max-w-6xl space-y-4 p-4">
                <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t(title)}</h1>
                <Card className="p-4">
                    <form onSubmit={apply} className="grid gap-3 sm:grid-cols-3 lg:grid-cols-7">
                        <Field label={t('branch')}>
                            <SelectInput value={f.branch_id} onChange={(e) => setF({ ...f, branch_id: e.target.value })}>
                                <option value="">{t('all')}</option>
                                {branches.map((b) => <option key={b.id} value={b.id}>{b.code} — {b.name}</option>)}
                            </SelectInput>
                        </Field>
                        <Field label={t('from_date')}><TextInput type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} /></Field>
                        <Field label={t('to_date')}><TextInput type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} /></Field>
                        <Field label={t('status')}>
                            <SelectInput value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })}>
                                <option value="">{t('all')}</option>
                                <option value="Pending">{t('acc_status_pending')}</option>
                                <option value="Authorized">{t('acc_status_authorized')}</option>
                                <option value="Cancelled">{t('acc_status_cancelled')}</option>
                            </SelectInput>
                        </Field>
                        <Field label={t('acc_voucher_type')}>
                            <SelectInput value={f.voucher_type} onChange={(e) => setF({ ...f, voucher_type: e.target.value })}>
                                <option value="">{t('all')}</option>
                                <option value="Receipt">{t('receipt')}</option>
                                <option value="Payment">{t('payment')}</option>
                                <option value="Journal">{t('acc_journal')}</option>
                                <option value="Contra">{t('acc_contra')}</option>
                            </SelectInput>
                        </Field>
                        <Field label={t('search')}><TextInput value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} placeholder={t('acc_voucher_no')} /></Field>
                        <div className="flex items-end"><PrimaryButton type="submit">{t('filter')}</PrimaryButton></div>
                    </form>
                </Card>
                <Card className="p-0">
                    <ResponsiveTable head={[t('acc_voucher_no'), t('date'), t('branch'), t('acc_voucher_type'), t('amount'), t('status'), t('posted_by'), '']}>
                        {vouchers.map((v) => (
                            <tr key={v.id} className="border-t border-slate-100 dark:border-slate-700">
                                <td className="px-3 py-2 font-medium">
                                    {v.batch_number}
                                    {v.backdated && <span className="ml-1 rounded bg-orange-100 px-1.5 py-0.5 text-xs font-medium text-orange-700 dark:bg-orange-900/40 dark:text-orange-300">{t('acc_backdated')}</span>}
                                </td>
                                <td className="px-3 py-2">{v.transaction_date}</td>
                                <td className="px-3 py-2">{v.branch}</td>
                                <td className="px-3 py-2">{v.voucher_type}</td>
                                <td className="px-3 py-2 text-right">{Number(v.total_amount).toLocaleString()}</td>
                                <td className="px-3 py-2"><span className={`rounded px-2 py-0.5 text-xs font-medium ${STATUS_TONES[v.status] ?? ''}`}>{v.status}</span></td>
                                <td className="px-3 py-2">{v.entry_by}</td>
                                <td className="px-3 py-2 text-right">
                                    <Link href={`/accounts/vouchers/${v.id}`} className="text-sm font-medium text-cyan-700 hover:underline dark:text-cyan-300">{t('view')}</Link>
                                </td>
                            </tr>
                        ))}
                        {vouchers.length === 0 && (
                            <tr><td colSpan={8} className="px-3 py-6 text-center text-slate-500">{t('no_data')}</td></tr>
                        )}
                    </ResponsiveTable>
                </Card>
            </div>
        </AppLayout>
    );
}
