import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ActionButton, Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Member Collection List (Step 21): per-member batch rows with cancel. */
export default function MemberCollectionList({ rows, filters }) {
    const [searching, setSearching] = useState(false);
    const [values, setValues] = useState({ from: filters.from ?? '', upto: filters.upto ?? '', member: filters.member ?? '' });

    const search = (e) => {
        e.preventDefault();
        router.get('/collection/member-list', values, {
            preserveState: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });
    };
    const cancel = (row, cb) => router.post(`/collection/batches/${row.batch_id}/cancel-member`, { member_id: row.member_id }, { preserveScroll: true, ...cb });

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('member_collection_list')}</h1>
            <Card className="mb-4">
                <form onSubmit={search} className="flex flex-wrap items-end gap-3">
                    <Field label={t('from_date')}>
                        <input type="date" className={inputCls} value={values.from} onChange={(e) => setValues({ ...values, from: e.target.value })} />
                    </Field>
                    <Field label={t('upto_date')}>
                        <input type="date" className={inputCls} value={values.upto} onChange={(e) => setValues({ ...values, upto: e.target.value })} />
                    </Field>
                    <div className="min-w-48 flex-1">
                        <Field label={t('member_no')}>
                            <input className={inputCls} value={values.member} onChange={(e) => setValues({ ...values, member: e.target.value })} />
                        </Field>
                    </div>
                    <PrimaryButton type="submit" loading={searching}>{t('search')}</PrimaryButton>
                </form>
            </Card>

            <Card>
                <ResponsiveTable head={[t('receive_date'), t('member_no'), t('member_name'), t('samity'), t('deposit_amount'), t('loan_amount'), t('share_amount'), t('total_receive'), t('receive_by'), t('actions')]}>
                    {rows.map((r) => (
                        <tr key={`${r.batch_id}-${r.member_id}`} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{r.date}</td>
                            <td className="px-3 py-2">{r.member_no}</td>
                            <td className="px-3 py-2">{r.member_name}</td>
                            <td className="px-3 py-2">{r.samity_name ?? '—'}</td>
                            <td className="px-3 py-2 text-right">{r.deposit_amount}</td>
                            <td className="px-3 py-2 text-right">{r.loan_amount}</td>
                            <td className="px-3 py-2 text-right">{r.share_amount}</td>
                            <td className="px-3 py-2 text-right font-medium">{r.total_receive}</td>
                            <td className="px-3 py-2">{r.entry_by ?? '—'}</td>
                            <td className="px-3 py-2">
                                {r.cancelled
                                    ? <span className="text-zinc-500">{t('cancelled')}</span>
                                    : <ActionButton fire={(cb) => cancel(r, cb)} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('cancel')}</ActionButton>}
                            </td>
                        </tr>
                    ))}
                </ResponsiveTable>
                {rows.length === 0 && <p className="py-4 text-sm text-zinc-500">{t('no_data_found')}</p>}
            </Card>
        </AppLayout>
    );
}
