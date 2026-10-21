import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

function xsrf() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/** Loan account opening (Step 17) — member / staff / product modes. */
export default function LoanOpening({ mode, proposal, member, memberQuery, employee, employeeQuery, products, frequencies, feeTypes, canOverride, businessDate, productLoans }) {
    const [calc, setCalc] = useState(null);
    const form = useForm({
        member_id: proposal?.member_id ?? member?.id ?? '',
        employee_id: employee?.id ?? '',
        loan_proposal_id: proposal?.id ?? '',
        loan_product_id: proposal?.loan_product_id ?? products[0]?.id ?? '',
        opening_date: businessDate ?? '', loan_purpose: proposal?.loan_purpose ?? '',
        loan_amount: proposal?.loan_amount ?? '', down_payment: 0, block_account: false,
        frequency: proposal?.frequency ?? 'M', no_of_installments: proposal?.no_of_installments ?? 12,
        profit_rate: proposal?.profit_rate ?? products[0]?.profit_rate ?? '',
        installment_from_date: proposal?.installment_from_date ?? businessDate ?? '',
        payment_type: '', payment_to_account: '', invoice_number: '',
        invoice_total_bill: '', invoice_date: '',
        installment_reminder: false, overdue_reminder: false,
        fees: Object.fromEntries(feeTypes.map((f) => [f, 0])),
        guarantors: proposal?.guarantors ?? [],
    });
    const product = products.find((p) => String(p.id) === String(form.data.loan_product_id));
    const allowedFreqs = frequencies.filter((f) => (product?.allowed_frequencies ?? []).includes(f.code));
    const lookupMember = (e) => { e.preventDefault(); router.get('/loans/opening', { mode, member: e.target.elements.member.value }, { preserveState: true }); };
    const lookupEmployee = (e) => { e.preventDefault(); router.get('/loans/opening', { mode, employee: e.target.elements.employee.value }, { preserveState: true }); };
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
            }),
        });
        if (response.ok) setCalc(await response.json());
    };
    const addGuarantor = () => form.setData('guarantors', [...form.data.guarantors, { name: '', relation: '', phone: '', address: '', national_id: '' }]);
    const setGuarantor = (i, key, value) => form.setData('guarantors', form.data.guarantors.map((g, j) => (j === i ? { ...g, [key]: value } : g)));
    const submit = (e) => { e.preventDefault(); form.post(`/loans/opening?mode=${mode}`); };
    const switchMode = (m) => router.get('/loans/opening', { mode: m }, { preserveState: false });

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t(mode === 'staff' ? 'staff_loan_opening' : mode === 'product' ? 'product_loan_opening' : 'loan_account_opening')}</h1>
            <div className="mb-4 flex flex-wrap gap-2">
                {['member', 'staff', 'product'].map((m) => (
                    <button key={m} type="button" onClick={() => switchMode(m)} className={`min-h-[44px] rounded-lg px-3 py-2 text-sm ${mode === m ? 'bg-emerald-600 text-white' : 'border border-zinc-300 dark:border-zinc-700'}`}>
                        {t(m === 'member' ? 'loan_account_opening' : m === 'staff' ? 'staff_loan_opening' : 'product_loan_opening')}
                    </button>
                ))}
            </div>

            {mode !== 'staff' && (
                <Card className="mb-4">
                    <form onSubmit={lookupMember} className="flex flex-wrap items-end gap-3">
                        <div className="min-w-56 flex-1">
                            <Field label={`${t('member_no')} *`}>
                                <input name="member" className={inputCls} defaultValue={memberQuery} />
                            </Field>
                        </div>
                        <PrimaryButton type="submit">{t('search')}</PrimaryButton>
                    </form>
                </Card>
            )}
            {mode === 'staff' && (
                <Card className="mb-4">
                    <form onSubmit={lookupEmployee} className="flex flex-wrap items-end gap-3">
                        <div className="min-w-56 flex-1">
                            <Field label={`${t('employee')} *`}>
                                <input name="employee" className={inputCls} defaultValue={employeeQuery} />
                            </Field>
                        </div>
                        <PrimaryButton type="submit">{t('search')}</PrimaryButton>
                    </form>
                </Card>
            )}

            {(member || employee || proposal) && (
                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                            <Field label={`${t('account_type')} *`} error={form.errors.loan_product_id}>
                                <select className={inputCls} value={form.data.loan_product_id} onChange={(e) => {
                                    const p = products.find((x) => String(x.id) === e.target.value);
                                    form.setData((prev) => ({ ...prev, loan_product_id: e.target.value, profit_rate: p ? p.profit_rate : prev.profit_rate }));
                                }} required>
                                    {products.map((p) => <option key={p.id} value={p.id}>{p.code} — {p.name_en}</option>)}
                                </select>
                            </Field>
                            <Field label={t('opening_date')} error={form.errors.opening_date}>
                                <input type="date" className={inputCls} value={form.data.opening_date} onChange={(e) => form.setData('opening_date', e.target.value)} />
                            </Field>
                            {mode === 'product' && (
                                <>
                                    <Field label={`${t('invoice_number')} *`} error={form.errors.invoice_number}>
                                        <input className={inputCls} value={form.data.invoice_number} onChange={(e) => form.setData('invoice_number', e.target.value)} required />
                                    </Field>
                                    <Field label={`${t('total_bill')} *`}>
                                        <input type="number" step="0.01" min="0" className={inputCls} value={form.data.invoice_total_bill} onChange={(e) => form.setData('invoice_total_bill', e.target.value)} required />
                                    </Field>
                                    <Field label={t('invoice_date')} >
                                        <input type="date" className={inputCls} value={form.data.invoice_date} onChange={(e) => form.setData('invoice_date', e.target.value)} />
                                    </Field>
                                </>
                            )}
                            <Field label={t('loan_purpose')}>
                                <input className={inputCls} value={form.data.loan_purpose} onChange={(e) => form.setData('loan_purpose', e.target.value)} />
                            </Field>
                            <Field label={`${t('loan_amount')} *`} error={form.errors.loan_amount}>
                                <input type="number" step="0.01" min="0" className={inputCls} value={form.data.loan_amount} onChange={(e) => form.setData('loan_amount', e.target.value)} onBlur={runPreview} required />
                            </Field>
                            <Field label={t('down_payment')}>
                                <input type="number" step="0.01" min="0" className={inputCls} value={form.data.down_payment} onChange={(e) => form.setData('down_payment', e.target.value)} />
                            </Field>
                            <Field label={`${t('frequency')} *`} error={form.errors.frequency}>
                                <select className={inputCls} value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)} onBlur={runPreview} required>
                                    {allowedFreqs.map((f) => <option key={f.code} value={f.code}>{f.code} — {f.label_en}</option>)}
                                </select>
                            </Field>
                            <Field label={`${t('no_of_installments')} *`} error={form.errors.no_of_installments}>
                                <input type="number" min="1" className={inputCls} value={form.data.no_of_installments} onChange={(e) => form.setData('no_of_installments', e.target.value)} onBlur={runPreview} required />
                            </Field>
                            <Field label={t('profit_rate')} error={form.errors.profit_rate}>
                                <input type="number" step="0.01" min="0" className={inputCls} value={form.data.profit_rate} onChange={(e) => form.setData('profit_rate', e.target.value)} onBlur={runPreview} readOnly={!canOverride} />
                            </Field>
                            <Field label={t('installment_amount')}>
                                <input className={inputCls} value={calc?.installment_amount ?? ''} readOnly />
                            </Field>
                            <Field label={t('profit_amount')}>
                                <input className={inputCls} value={calc?.profit_amount ?? ''} readOnly />
                            </Field>
                            <Field label={t('total_repayment')}>
                                <input className={inputCls} value={calc?.total_repayment ?? ''} readOnly />
                            </Field>
                            <Field label={`${t('installment_from_date')} *`} error={form.errors.installment_from_date}>
                                <input type="date" className={inputCls} value={form.data.installment_from_date} onChange={(e) => form.setData('installment_from_date', e.target.value)} onBlur={runPreview} required />
                            </Field>
                            <Field label={t('installment_expire_date')}>
                                <input className={inputCls} value={calc?.installment_expire_date ?? ''} readOnly />
                            </Field>
                            <Field label={t('payment_type')}>
                                <input className={inputCls} value={form.data.payment_type} onChange={(e) => form.setData('payment_type', e.target.value)} />
                            </Field>
                            <Field label={t('payment_to_account')}>
                                <input className={inputCls} value={form.data.payment_to_account} onChange={(e) => form.setData('payment_to_account', e.target.value)} />
                            </Field>
                            <div className="sm:col-span-2">
                                <span className="mb-1 block text-sm font-medium">{t('loan_fees')}</span>
                                <div className="grid gap-3 sm:grid-cols-3">
                                    {feeTypes.map((fee) => (
                                        <Field key={fee} label={t(`fee_${fee}`)}>
                                            <input type="number" step="0.01" min="0" className={inputCls} value={form.data.fees[fee]} onChange={(e) => form.setData('fees', { ...form.data.fees, [fee]: e.target.value })} />
                                        </Field>
                                    ))}
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-4 sm:col-span-2">
                                <label className="flex min-h-[44px] items-center gap-2 text-sm">
                                    <input type="checkbox" className="h-5 w-5" checked={!!form.data.block_account} onChange={(e) => form.setData('block_account', e.target.checked)} />
                                    {t('block_account')}
                                </label>
                                <label className="flex min-h-[44px] items-center gap-2 text-sm">
                                    <input type="checkbox" className="h-5 w-5" checked={!!form.data.installment_reminder} onChange={(e) => form.setData('installment_reminder', e.target.checked)} />
                                    {t('installment_reminder')}
                                </label>
                                <label className="flex min-h-[44px] items-center gap-2 text-sm">
                                    <input type="checkbox" className="h-5 w-5" checked={!!form.data.overdue_reminder} onChange={(e) => form.setData('overdue_reminder', e.target.checked)} />
                                    {t('overdue_reminder')}
                                </label>
                            </div>
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
                            {form.errors.loan_proposal_id && <span className="text-sm text-red-600 dark:text-red-400 sm:col-span-2">{form.errors.loan_proposal_id}</span>}
                            <div className="sm:col-span-2">
                                <PrimaryButton type="submit" disabled={form.processing}>{t('open_loan_account')}</PrimaryButton>
                            </div>
                        </form>
                    </Card>
                    <Card>
                        <h2 className="mb-3 text-sm font-semibold tracking-wide text-zinc-500">{t(mode === 'staff' ? 'employee_profile' : 'member_profile')}</h2>
                        {member && (
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('member_no')}</dt><dd className="font-medium">{member.member_no}</dd></div>
                                <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('name')}</dt><dd className="font-medium">{member.name}</dd></div>
                                <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('mobile')}</dt><dd className="font-medium">{member.mobile}</dd></div>
                                <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('samity')}</dt><dd className="font-medium">{member.samity}</dd></div>
                                <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('share_amount')}</dt><dd className="font-medium">{member.share_amount}</dd></div>
                                <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('status')}</dt><dd className="font-medium">{member.status}</dd></div>
                            </dl>
                        )}
                        {employee && (
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('code')}</dt><dd className="font-medium">{employee.employee_code}</dd></div>
                                <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('name')}</dt><dd className="font-medium">{employee.name}</dd></div>
                                <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('mobile')}</dt><dd className="font-medium">{employee.mobile}</dd></div>
                                <div className="flex justify-between gap-3"><dt className="text-zinc-500">{t('designation')}</dt><dd className="font-medium">{employee.designation}</dd></div>
                            </dl>
                        )}
                    </Card>
                </div>
            )}

            {mode === 'product' && (
                <Card className="mt-4">
                    <h2 className="mb-3 text-base font-semibold">{t('loan_list')}</h2>
                    <ResponsiveTable head={[t('loan_number'), t('member_no'), t('invoice_number'), t('frequency'), t('installment_from_date'), t('loan_amount'), t('profit_rate'), t('profit_amount'), t('total_repayment'), t('no_of_installments'), t('installment_amount'), t('profit_paid'), t('installment_expire_date'), t('status')]}>
                        {productLoans.map((l) => (
                            <tr key={l.id} className="border-b border-zinc-100 dark:border-zinc-800">
                                <td className="px-3 py-2">{l.loan_number}</td>
                                <td className="px-3 py-2">{l.member ?? '—'}</td>
                                <td className="px-3 py-2">{l.invoice_number}</td>
                                <td className="px-3 py-2">{l.frequency}</td>
                                <td className="px-3 py-2">{l.installment_from_date}</td>
                                <td className="px-3 py-2">{l.loan_amount}</td>
                                <td className="px-3 py-2">{l.profit_rate}</td>
                                <td className="px-3 py-2">{l.profit_amount}</td>
                                <td className="px-3 py-2">{l.total_repayment}</td>
                                <td className="px-3 py-2">{l.no_of_installments}</td>
                                <td className="px-3 py-2">{l.installment_amount}</td>
                                <td className="px-3 py-2">{l.paid}</td>
                                <td className="px-3 py-2">{l.installment_expire_date}</td>
                                <td className="px-3 py-2">{l.status}</td>
                            </tr>
                        ))}
                        {productLoans.length === 0 && <tr><td className="px-3 py-3" colSpan={14}>{t('no_data_found')}</td></tr>}
                    </ResponsiveTable>
                </Card>
            )}
        </AppLayout>
    );
}
