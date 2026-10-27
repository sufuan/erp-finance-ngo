import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Card, Field, PrimaryButton, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Deposit account opening (Step 10) — member lookup + MEMBER PROFILE panel. */
export default function DepositOpening({ products, member, memberQuery, businessDate }) {
    const [needle, setNeedle] = useState(memberQuery ?? '');
    const [searching, setSearching] = useState(false);
    const [showNominee, setShowNominee] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        member_id: member?.id ?? '', deposit_product_id: '', amount: '', title: member?.name ?? '',
        address: member?.present_address ?? '', phone: member?.mobile ?? '', opening_date: businessDate ?? '',
        profit_rate: '', term_months: '', credit_limit: '',
        nominee_name: '', nominee_dob: '', nominee_phone: '', nominee_relation: '', nominee_address: '',
    });

    // The member lookup preserves form state, so useForm keeps the values
    // it was first mounted with (member === null → member_id ''). Sync the
    // loaded member into the form whenever it changes, otherwise the
    // submit posts an empty member_id and fails with no visible error.
    useEffect(() => {
        if (!member) return;
        setData('member_id', member.id);
        if (!data.title) setData('title', member.name ?? '');
        if (!data.address) setData('address', member.present_address ?? '');
        if (!data.phone) setData('phone', member.mobile ?? '');
    }, [member?.id]);

    const product = products.find((p) => String(p.id) === String(data.deposit_product_id));
    const termsYears = product?.config?.terms_years ?? null;

    const pickProduct = (id) => {
        const p = products.find((x) => String(x.id) === String(id));
        setData((prev) => ({ ...prev, deposit_product_id: id, profit_rate: p ? p.default_profit_rate : prev.profit_rate }));
    };
    const lookup = (e) => { e.preventDefault(); router.get('/deposits/opening', { member: needle }, { preserveState: true, onStart: () => setSearching(true), onFinish: () => setSearching(false) }); };
    const submit = (e) => { e.preventDefault(); post('/deposits'); };

    const text = (key, label, type = 'text', required = false) => (
        <Field label={t(label)} required={required} error={errors[key]}>
            <input type={type} className={inputCls} value={data[key]} onChange={(e) => setData(key, e.target.value)} required={required} />
        </Field>
    );

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('deposit_account_opening')}</h1>

            <Card className="mb-4">
                <form onSubmit={lookup} className="flex flex-wrap items-end gap-3">
                    <div className="min-w-56 flex-1">
                        <Field label={t('member_no')}>
                            <input className={inputCls} value={needle} onChange={(e) => setNeedle(e.target.value)} />
                        </Field>
                    </div>
                    <PrimaryButton type="submit" loading={searching}>{t('search')}</PrimaryButton>
                </form>
            </Card>

            {member && (
                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                            {errors.member_id && <span className="text-sm text-red-600 dark:text-red-400 sm:col-span-2">{errors.member_id}</span>}
                            <Field label={t('samity')}>
                                <input className={inputCls} value={member.samity ?? ''} readOnly />
                            </Field>
                            <Field label={t('account_type')} required error={errors.deposit_product_id}>
                                <select className={inputCls} value={data.deposit_product_id} onChange={(e) => pickProduct(e.target.value)} required>
                                    <option value="">—</option>
                                    {products.map((p) => <option key={p.id} value={p.id}>{p.code} — {p.name_en}</option>)}
                                </select>
                            </Field>
                            {text('amount', 'amount', 'number')}
                            {text('title', 'account_title')}
                            {text('address', 'account_address')}
                            {text('phone', 'phone_number', 'text', true)}
                            {text('opening_date', 'opening_date', 'date', true)}
                            {text('profit_rate', 'profit_rate', 'number', true)}
                            {termsYears ? (
                                <Field label={t('term')} error={errors.term_months}>
                                    <select className={inputCls} value={data.term_months} onChange={(e) => setData('term_months', e.target.value)}>
                                        <option value="">—</option>
                                        {termsYears.map((y) => <option key={y} value={y * 12}>{y} {t('years')}</option>)}
                                    </select>
                                </Field>
                            ) : (
                                product?.maturity_months ? text('term_months', 'maturity_months', 'number') : null
                            )}
                            {text('credit_limit', 'credit_limit', 'number')}
                            <div className="sm:col-span-2">
                                <button type="button" onClick={() => setShowNominee(!showNominee)} className="min-h-[44px] rounded-lg border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">
                                    {t('add_nominee')}
                                </button>
                            </div>
                            {showNominee && (
                                <>
                                    {text('nominee_name', 'nominee_name')}
                                    {text('nominee_dob', 'nominee_dob', 'date')}
                                    {text('nominee_phone', 'nominee_phone')}
                                    {text('nominee_relation', 'nominee_relation')}
                                    {text('nominee_address', 'nominee_address')}
                                </>
                            )}
                            <div className="sm:col-span-2">
                                <PrimaryButton type="submit" loading={processing}>{t('open_deposit_account')}</PrimaryButton>
                            </div>
                        </form>
                    </Card>

                    <Card>
                        <h2 className="mb-3 font-medium">{t('member_profile')}</h2>
                        {member.photo_url && <img src={member.photo_url} alt="" className="mb-3 h-20 w-20 rounded-2xl object-cover" />}
                        <dl className="space-y-2 text-sm">
                            <div><dt className="text-zinc-500">{t('full_name')}</dt><dd className="font-medium">{member.name}</dd></div>
                            <div><dt className="text-zinc-500">{t('member_no')}</dt><dd>{member.member_no}</dd></div>
                            <div><dt className="text-zinc-500">{t('samity')}</dt><dd>{member.samity ?? '—'}</dd></div>
                            <div><dt className="text-zinc-500">{t('branch')}</dt><dd>{member.branch ?? '—'}</dd></div>
                            <div><dt className="text-zinc-500">{t('mobile')}</dt><dd>{member.mobile}</dd></div>
                            <div><dt className="text-zinc-500">{t('share_amount')}</dt><dd>{member.share_amount}</dd></div>
                            <div><dt className="text-zinc-500">{t('nominee')}</dt><dd>{member.nominee?.name ?? '—'}</dd></div>
                        </dl>
                    </Card>
                </div>
            )}
        </AppLayout>
    );
}
