import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ActionButton, Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Samity Collection List (Step 21): batch rows per samity with cancel. */
export default function SamityCollectionList({ samities, rows, filters }) {
    const [searching, setSearching] = useState(false);
    const [values, setValues] = useState({ from: filters.from ?? '', upto: filters.upto ?? '', samity_id: filters.samity_id ?? '' });

    const search = (e) => {
        e.preventDefault();
        router.get('/collection/samity-list', values, {
            preserveState: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });
    };
    const cancel = (id, cb) => router.post(`/collection/batches/${id}/cancel`, {}, { preserveScroll: true, ...cb });

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('samity_collection_list')}</h1>
            <Card className="mb-4">
                <form onSubmit={search} className="flex flex-wrap items-end gap-3">
                    <div className="min-w-56 flex-1">
                        <Field label={t('samity')}>
                            <select className={inputCls} value={values.samity_id} onChange={(e) => setValues({ ...values, samity_id: e.target.value })}>
                                <option value="">{t('all')}</option>
                                {samities.map((s) => <option key={s.id} value={s.id}>{s.code} — {s.name}</option>)}
                            </select>
                        </Field>
                    </div>
                    <Field label={t('from_date')}>
                        <input type="date" className={inputCls} value={values.from} onChange={(e) => setValues({ ...values, from: e.target.value })} />
                    </Field>
                    <Field label={t('upto_date')}>
                        <input type="date" className={inputCls} value={values.upto} onChange={(e) => setValues({ ...values, upto: e.target.value })} />
                    </Field>
                    <PrimaryButton type="submit" loading={searching}>{t('search')}</PrimaryButton>
                </form>
            </Card>

            <Card>
                <ResponsiveTable head={[t('receive_date'), t('samity'), t('deposit_amount'), t('loan_amount'), t('share_amount'), t('total_receive'), t('total_dues'), t('status'), t('receive_by'), t('actions')]}>
                    {rows.map((r) => (
                        <tr key={r.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{r.date}</td>
                            <td className="px-3 py-2">{r.samity}</td>
                            <td className="px-3 py-2 text-right">{r.deposit_amount}</td>
                            <td className="px-3 py-2 text-right">{r.loan_amount}</td>
                            <td className="px-3 py-2 text-right">{r.share_amount}</td>
                            <td className="px-3 py-2 text-right font-medium">{r.total_receive}</td>
                            <td className="px-3 py-2 text-right text-red-600">{r.total_dues}</td>
                            <td className="px-3 py-2">{r.status}</td>
                            <td className="px-3 py-2">{r.entry_by ?? '—'}</td>
                            <td className="px-3 py-2">
                                {r.status === 'Cancelled'
                                    ? <span className="text-zinc-500">{t('cancelled')}</span>
                                    : <ActionButton fire={(cb) => cancel(r.id, cb)} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('cancel')}</ActionButton>}
                            </td>
                        </tr>
                    ))}
                </ResponsiveTable>
                {rows.length === 0 && <p className="py-4 text-sm text-zinc-500">{t('no_data_found')}</p>}
            </Card>
        </AppLayout>
    );
}
