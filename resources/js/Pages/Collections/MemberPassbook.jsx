import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/**
 * Member Passbook (Step 23): one member's merged deposit + loan
 * statement with running balance, printable as PDF.
 */
export default function MemberPassbook({ member, memberQuery, accounts, loans, rows, filters }) {
    const [searching, setSearching] = useState(false);
    const [values, setValues] = useState({
        from: filters.from ?? '', upto: filters.upto ?? '',
        account_status: filters.account_status ?? 'all',
        deposit_account_id: filters.deposit_account_id ?? '',
        loan_account_id: filters.loan_account_id ?? '',
    });

    const run = (e, needle) => {
        e.preventDefault();
        router.get('/collection/passbook', { member: needle ?? memberQuery, ...values }, {
            preserveState: true, onStart: () => setSearching(true), onFinish: () => setSearching(false),
        });
    };
    const pdfUrl = () => {
        const params = new URLSearchParams({ member: memberQuery, ...Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '')) });
        return `/collection/passbook/pdf?${params.toString()}`;
    };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('member_passbook')}</h1>
            <Card className="mb-4">
                <form onSubmit={(e) => run(e, e.target.elements.member.value)} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label={t('member_id')} required>
                        <input name="member" className={inputCls} defaultValue={memberQuery} required />
                    </Field>
                    <Field label={t('deposit_account')}>
                        <select className={inputCls} value={values.deposit_account_id} onChange={(e) => setValues({ ...values, deposit_account_id: e.target.value })}>
                            <option value="">{t('all')}</option>
                            {accounts.map((a) => <option key={a.id} value={a.id}>{a.account_number} · {a.product} · {a.current_balance}</option>)}
                        </select>
                    </Field>
                    <Field label={t('loan_account')}>
                        <select className={inputCls} value={values.loan_account_id} onChange={(e) => setValues({ ...values, loan_account_id: e.target.value })}>
                            <option value="">{t('all')}</option>
                            {loans.map((l) => <option key={l.id} value={l.id}>{l.loan_number} · {l.status}</option>)}
                        </select>
                    </Field>
                    <Field label={t('from_date')}>
                        <input type="date" className={inputCls} value={values.from} onChange={(e) => setValues({ ...values, from: e.target.value })} />
                    </Field>
                    <Field label={t('upto_date')}>
                        <input type="date" className={inputCls} value={values.upto} onChange={(e) => setValues({ ...values, upto: e.target.value })} />
                    </Field>
                    <Field label={t('account_status')}>
                        <select className={inputCls} value={values.account_status} onChange={(e) => setValues({ ...values, account_status: e.target.value })}>
                            <option value="all">{t('all')}</option>
                            <option value="active">{t('active')}</option>
                            <option value="closed">{t('closed')}</option>
                        </select>
                    </Field>
                    <div className="flex items-end gap-2">
                        <PrimaryButton type="submit" loading={searching}>{t('search')}</PrimaryButton>
                        {member && (
                            <a href={pdfUrl()} target="_blank" rel="noreferrer" className="inline-flex min-h-[44px] items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                {t('print')}
                            </a>
                        )}
                    </div>
                </form>
                {member && (
                    <p className="mt-3 text-sm"><span className="font-medium">{member.member_no}</span> — {member.name} · {member.samity ?? '—'} · {t('share')}: {member.share_amount}</p>
                )}
            </Card>

            {member && (
                <Card>
                    <ResponsiveTable head={[t('date'), t('particulars'), t('debit'), t('credit'), t('balance')]}>
                        {rows.map((r, i) => (
                            <tr key={i} className="border-b border-zinc-100 dark:border-zinc-800">
                                <td className="px-3 py-2">{r.date}</td>
                                <td className="px-3 py-2">{r.particulars}</td>
                                <td className="px-3 py-2 text-right">{r.debit}</td>
                                <td className="px-3 py-2 text-right">{r.credit}</td>
                                <td className="px-3 py-2 text-right font-medium">{r.balance}</td>
                            </tr>
                        ))}
                    </ResponsiveTable>
                    {rows.length === 0 && <p className="py-4 text-sm text-zinc-500">{t('no_data_found')}</p>}
                </Card>
            )}
        </AppLayout>
    );
}
