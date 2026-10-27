import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Card, Field, PrimaryButton, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

function xsrf() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/** Loan proposal (Step 16) — calc fields come from the ScheduleEngine preview. */
export default function ProposalCreate({ member, memberQuery, products, frequencies, fdrAccounts, canOverride, businessDate }) {
    const [calc, setCalc] = useState(null);
    const [searching, setSearching] = useState(false);
    const form = useForm({
        member_id: member?.id ?? '', loan_product_id: products[0]?.id ?? '',
        proposal_date: businessDate ?? '', loan_purpose: '', loan_amount: '',
        fdr_account_id: '', frequency: 'M', no_of_installments: 12,
        profit_rate: products[0]?.profit_rate ?? '', installment_from_date: businessDate ?? '',
        guarantors: [],
    });
    // The member lookup above preserves form state, so useForm keeps the
    // values it was first mounted with (member === null → member_id '').
    // Sync the loaded member's id into the form whenever it changes,
    // otherwise the submit posts an empty member_id and validation fails.
    useEffect(() => { if (member) form.setData('member_id', member.id); }, [member?.id]);
    const lookup = (e) => { e.preventDefault(); router.get('/loans/proposals/create', { member: e.target.elements.member.value }, { preserveState: true, onStart: () => setSearching(true), onFinish: () => setSearching(false) }); };
    const product = products.find((p) => String(p.id) === String(form.data.loan_product_id));
    const allowedFreqs = frequencies.filter((f) => (product?.allowed_frequencies ?? []).includes(f.code));

    const runPreview = async () => {
        if (!form.data.loan_amount || !form.data.no_of_installments || !form.data.installment_from_date) return;
        const response = await fetch('/loans/proposals/preview', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json' },
            body: JSON.stringify({
                loan_product_id: form.data.loan_product_id, loan_amount: form.data.loan_amount,
                profit_rate: form.data.profit_rate, frequency: form.data.frequency,
                no_of_installments: form.data.no_of_installments,
                installment_from_date: form.data.installment_from_date,
                member_id: form.data.member_id || undefined,
            }),
        });
        if (response.ok) setCalc(await response.json());
    };
    const addGuarantor = () => form.setData('guarantors', [...form.data.guarantors, { name: '', relation: '', phone: '', address: '', national_id: '' }]);
    const setGuarantor = (i, key, value) => {
        const rows = form.data.guarantors.map((g, j) => (j === i ? { ...g, [key]: value } : g));
        form.setData('guarantors', rows);
    };
    const submit = (e) => { e.preventDefault(); form.post('/loans/proposals'); };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('loan_proposal')}</h1>
            <Card className="mb-4">
                <form onSubmit={lookup} className="flex flex-wrap items-end gap-3">
                    <div className="min-w-56 flex-1">
                        <Field label={t('member_no')} required>
                            <input name="member" className={inputCls} defaultValue={memberQuery} />
                        </Field>
                    </div>
                    <PrimaryButton type="submit" loading={searching}>{t('search')}</PrimaryButton>
                </form>
            </Card>

            {member && (
                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                            <Field label={t('account_type')} required error={form.errors.loan_product_id}>
                                <select className={inputCls} value={form.data.loan_product_id} onChange={(e) => {
                                    const p = products.find((x) => String(x.id) === e.target.value);
                                    form.setData((prev) => ({ ...prev, loan_product_id: e.target.value, profit_rate: p ? p.profit_rate : prev.profit_rate }));
                                }} required>
                                    {products.map((p) => <option key={p.id} value={p.id}>{p.code} — {p.name_en}</option>)}
                                </select>
                            </Field>
                            <Field label={t('samity')}>
                                <input className={inputCls} value={member.samity ?? ''} readOnly />
                            </Field>
                            <Field label={t('proposal_date')} required error={form.errors.proposal_date}>
                                <input type="date" className={inputCls} value={form.data.proposal_date} onChange={(e) => form.setData('proposal_date', e.target.value)} />
                            </Field>
                            <Field label={t('loan_purpose')}>
                                <input className={inputCls} value={form.data.loan_purpose} onChange={(e) => form.setData('loan_purpose', e.target.value)} />
                            </Field>
                            <Field label={t('loan_amount')} required error={form.errors.loan_amount}>
                                <input type="number" step="0.01" min="0" className={inputCls} value={form.data.loan_amount} onChange={(e) => form.setData('loan_amount', e.target.value)} onBlur={runPreview} required />
                            </Field>
                            <Field label={t('fdr_account')}>
                                <select className={inputCls} value={form.data.fdr_account_id} onChange={(e) => form.setData('fdr_account_id', e.target.value)}>
                                    <option value="">—</option>
                                    {fdrAccounts.map((a) => <option key={a.id} value={a.id}>{a.account_number} · {a.current_balance}</option>)}
                                </select>
                            </Field>
                            <Field label={t('frequency')} required error={form.errors.frequency}>
                                <select className={inputCls} value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)} onBlur={runPreview} required>
                                    {allowedFreqs.map((f) => <option key={f.code} value={f.code}>{f.code} — {f.label_en}</option>)}
                                </select>
                            </Field>
                            <Field label={t('no_of_installments')} required error={form.errors.no_of_installments}>
                                <input type="number" min="1" className={inputCls} value={form.data.no_of_installments} onChange={(e) => form.setData('no_of_installments', e.target.value)} onBlur={runPreview} required />
                            </Field>
                            <Field label={t('profit_rate')} error={form.errors.profit_rate}>
                                <input type="number" step="0.01" min="0" className={inputCls} value={form.data.profit_rate} onChange={(e) => form.setData('profit_rate', e.target.value)} onBlur={runPreview} readOnly={!canOverride} />
                            </Field>
                            <Field label={t('installment_amount')}>
                                <input className={inputCls} value={calc?.installment_amount ?? ''} readOnly />
                            </Field>
                            <Field label={t('actual_installment_amount')}>
                                <input className={inputCls} value={calc?.actual_installment_amount ?? ''} readOnly />
                            </Field>
                            <Field label={t('last_installment_amount')}>
                                <input className={inputCls} value={calc?.last_installment_amount ?? ''} readOnly />
                            </Field>
                            <Field label={t('profit_amount')}>
                                <input className={inputCls} value={calc?.profit_amount ?? ''} readOnly />
                            </Field>
                            <Field label={t('total_repayment')}>
                                <input className={inputCls} value={calc?.total_repayment ?? ''} readOnly />
                            </Field>
                            <Field label={t('installment_from_date')} required error={form.errors.installment_from_date}>
                                <input type="date" className={inputCls} value={form.data.installment_from_date} onChange={(e) => form.setData('installment_from_date', e.target.value)} onBlur={runPreview} required />
                            </Field>
                            <Field label={t('installment_expire_date')}>
                                <input className={inputCls} value={calc?.installment_expire_date ?? ''} readOnly />
                            </Field>
                            {form.errors.member_id && <span className="text-sm text-red-600 dark:text-red-400 sm:col-span-2">{form.errors.member_id}</span>}
                            <div className="sm:col-span-2">
                                <button type="button" onClick={addGuarantor} className="min-h-[44px] rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700">{t('add_guarantor')}</button>
                            </div>
                            {form.data.guarantors.map((g, i) => (
                                <div key={i} className="grid gap-3 sm:col-span-2 sm:grid-cols-3">
                                    <input className={inputCls} placeholder={t('guarantor_name')} value={g.name} onChange={(e) => setGuarantor(i, 'name', e.target.value)} />
                                    <input className={inputCls} placeholder={t('relation')} value={g.relation} onChange={(e) => setGuarantor(i, 'relation', e.target.value)} />
                                    <input className={inputCls} placeholder={t('phone')} value={g.phone} onChange={(e) => setGuarantor(i, 'phone', e.target.value)} />
                                    <input className={inputCls} placeholder={t('address')} value={g.address} onChange={(e) => setGuarantor(i, 'address', e.target.value)} />
                                    <input className={inputCls} placeholder={t('national_id')} value={g.national_id} onChange={(e) => setGuarantor(i, 'national_id', e.target.value)} />
                                </div>
                            ))}
                            <div className="sm:col-span-2">
                                <PrimaryButton type="submit" loading={form.processing}>{t('submit_application')}</PrimaryButton>
                            </div>
                        </form>
                    </Card>
                    <Card>
                        <h2 className="mb-3 text-sm font-semibold tracking-wide text-zinc-500">{t('member_profile')}</h2>
                        <dl className="space-y-2 text-sm">
                            <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('member_no')}</dt><dd className="font-medium">{member.member_no}</dd></div>
                            <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('name')}</dt><dd className="font-medium">{member.name}</dd></div>
                            <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('mobile')}</dt><dd className="font-medium">{member.mobile}</dd></div>
                            <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('samity')}</dt><dd className="font-medium">{member.samity}</dd></div>
                            <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('share_amount')}</dt><dd className="font-medium">{member.share_amount}</dd></div>
                            <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('savings_balance')}</dt><dd className="font-medium">{member.savings_balance}</dd></div>
                            <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('status')}</dt><dd className="font-medium">{member.status}</dd></div>
                        </dl>
                    </Card>
                </div>
            )}
        </AppLayout>
    );
}
