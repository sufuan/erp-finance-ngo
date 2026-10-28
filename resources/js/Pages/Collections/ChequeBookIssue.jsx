import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ActionButton, Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Cheque Book Issue (Step 23): member lookup → issue book → list. */
export default function ChequeBookIssue({ member, memberQuery, rows, businessDate }) {
    const [searching, setSearching] = useState(false);
    const form = useForm({ member_id: member?.id ?? '', cheque_prefix: '', start_leaf: '', end_leaf: '', fee_amount: '', document_number: '' });

    // Sync the loaded member's id into the form whenever it changes,
    // otherwise the submit posts an empty member_id and validation fails.
    useEffect(() => { if (member) form.setData('member_id', member.id); }, [member?.id]);

    const lookup = (e) => {
        e.preventDefault();
        const needle = e.target.elements.member.value;
        router.get('/collection/cheque-issue', { member: needle }, { preserveState: true, onStart: () => setSearching(true), onFinish: () => setSearching(false) });
    };
    const submit = (e) => { e.preventDefault(); form.post('/collection/cheque-issue', { preserveScroll: true }); };
    const cancel = (id, cb) => router.post(`/collection/cheque-issue/${id}/cancel`, {}, { preserveScroll: true, ...cb });

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('cheque_book_issue')}</h1>
            <Card className="mb-4">
                <form onSubmit={lookup} className="flex flex-wrap items-end gap-3">
                    <div className="min-w-56 flex-1">
                        <Field label={t('member_id')} required>
                            <input name="member" className={inputCls} defaultValue={memberQuery} required />
                        </Field>
                    </div>
                    <PrimaryButton type="submit" loading={searching}>{t('search')}</PrimaryButton>
                </form>
                {member && (
                    <p className="mt-3 text-sm"><span className="font-medium">{member.member_no}</span> — {member.name} · {member.samity ?? '—'}</p>
                )}
            </Card>

            {member && (
                <Card className="mb-4">
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Field label={t('cheque_prefix')} error={form.errors.cheque_prefix}>
                            <input className={inputCls} value={form.data.cheque_prefix} onChange={(e) => form.setData('cheque_prefix', e.target.value)} />
                        </Field>
                        <Field label={t('start_leaf_number')} required error={form.errors.start_leaf}>
                            <input className={inputCls} value={form.data.start_leaf} onChange={(e) => form.setData('start_leaf', e.target.value)} required />
                        </Field>
                        <Field label={t('end_leaf_number')} required error={form.errors.end_leaf}>
                            <input className={inputCls} value={form.data.end_leaf} onChange={(e) => form.setData('end_leaf', e.target.value)} required />
                        </Field>
                        <Field label={t('fee_amount')} error={form.errors.fee_amount}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={form.data.fee_amount} onChange={(e) => form.setData('fee_amount', e.target.value)} />
                        </Field>
                        <Field label={t('issue_date')}>
                            <input type="date" className={inputCls} value={businessDate ?? ''} readOnly />
                        </Field>
                        <Field label={t('document_number')} error={form.errors.document_number}>
                            <input className={inputCls} value={form.data.document_number} onChange={(e) => form.setData('document_number', e.target.value)} />
                        </Field>
                        <div className="flex items-end">
                            <PrimaryButton type="submit" loading={form.processing}>{t('issue_book')}</PrimaryButton>
                        </div>
                    </form>
                </Card>
            )}

            <Card>
                <ResponsiveTable head={[t('member_no'), t('member_name'), t('cheque_prefix'), t('start_leaf_number'), t('end_leaf_number'), t('fee_amount'), t('issue_date'), t('actions')]}>
                    {rows.map((r) => (
                        <tr key={r.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{r.member_no}</td>
                            <td className="px-3 py-2">{r.member_name}</td>
                            <td className="px-3 py-2">{r.cheque_prefix ?? '—'}</td>
                            <td className="px-3 py-2">{r.start_leaf}</td>
                            <td className="px-3 py-2">{r.end_leaf}</td>
                            <td className="px-3 py-2 text-right">{r.fee_amount}</td>
                            <td className="px-3 py-2">{r.issue_date}</td>
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
