import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ActionButton, Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/**
 * Other Collection Receive (Step 21): branch-level miscellaneous
 * receipts (types are admin-configurable in Settings), with the type
 * balance shown read-only and a searchable receipt list.
 */
export default function OtherCollection({ types, balances, rows, businessDate, filters }) {
    const form = useForm({ collection_type: types[0] ?? '', collection_account: '', amount: '', member: '', document_number: '', comments: '' });
    const [searching, setSearching] = useState(false);
    const [search, setSearch] = useState({ type: filters.type ?? '', from: filters.from ?? '', upto: filters.upto ?? '' });

    const submit = (e) => { e.preventDefault(); form.post('/collection/other', { preserveScroll: true, onSuccess: () => form.reset('amount', 'document_number', 'comments') }); };
    const runSearch = (e) => {
        e.preventDefault();
        router.get('/collection/other', search, { preserveState: true, onStart: () => setSearching(true), onFinish: () => setSearching(false) });
    };
    const cancel = (id, cb) => router.post(`/collection/other/${id}/cancel`, {}, { preserveScroll: true, ...cb });

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('other_collection_receive')}</h1>
            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('collection_type')} required error={form.errors.collection_type}>
                            <select className={inputCls} value={form.data.collection_type} onChange={(e) => form.setData('collection_type', e.target.value)} required>
                                {types.map((type) => <option key={type} value={type}>{type}</option>)}
                            </select>
                        </Field>
                        <Field label={t('collection_account')} error={form.errors.collection_account}>
                            <input className={inputCls} value={form.data.collection_account} onChange={(e) => form.setData('collection_account', e.target.value)} />
                        </Field>
                        <Field label={t('collection_amount')} required error={form.errors.amount}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} required />
                        </Field>
                        <Field label={t('collection_date')}>
                            <input type="date" className={inputCls} value={businessDate ?? ''} readOnly />
                        </Field>
                        <Field label={t('balance')}>
                            <input className={inputCls} value={balances[form.data.collection_type] ?? '0.00'} readOnly />
                        </Field>
                        <Field label={t('member_no')} error={form.errors.member}>
                            <input className={inputCls} value={form.data.member} onChange={(e) => form.setData('member', e.target.value)} />
                        </Field>
                        <Field label={t('document_number')} error={form.errors.document_number}>
                            <input className={inputCls} value={form.data.document_number} onChange={(e) => form.setData('document_number', e.target.value)} />
                        </Field>
                        <Field label={t('comments')} error={form.errors.comments}>
                            <textarea className={inputCls} rows={2} value={form.data.comments} onChange={(e) => form.setData('comments', e.target.value)} />
                        </Field>
                        <div className="flex items-end">
                            <PrimaryButton type="submit" loading={form.processing}>{t('collection_receive')}</PrimaryButton>
                        </div>
                    </form>
                </Card>
                <Card className="h-fit">
                    <h2 className="mb-3 font-medium">{t('search')}</h2>
                    <form onSubmit={runSearch} className="grid gap-3">
                        <Field label={t('collection_type')}>
                            <select className={inputCls} value={search.type} onChange={(e) => setSearch({ ...search, type: e.target.value })}>
                                <option value="">{t('all')}</option>
                                {types.map((type) => <option key={type} value={type}>{type}</option>)}
                            </select>
                        </Field>
                        <Field label={t('from_date')}>
                            <input type="date" className={inputCls} value={search.from} onChange={(e) => setSearch({ ...search, from: e.target.value })} />
                        </Field>
                        <Field label={t('upto_date')}>
                            <input type="date" className={inputCls} value={search.upto} onChange={(e) => setSearch({ ...search, upto: e.target.value })} />
                        </Field>
                        <PrimaryButton type="submit" loading={searching}>{t('search')}</PrimaryButton>
                    </form>
                </Card>
            </div>

            <Card className="mt-4">
                <ResponsiveTable head={[t('date'), t('collection_type'), t('collection_account'), t('member'), t('amount'), t('document_number'), t('actions')]}>
                    {rows.map((r) => (
                        <tr key={r.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{r.date}</td>
                            <td className="px-3 py-2">{r.type}</td>
                            <td className="px-3 py-2">{r.account ?? '—'}</td>
                            <td className="px-3 py-2">{r.member ?? '—'}</td>
                            <td className="px-3 py-2 text-right">{r.amount}</td>
                            <td className="px-3 py-2">{r.document_number ?? '—'}</td>
                            <td className="px-3 py-2">
                                {r.cancel_at
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
