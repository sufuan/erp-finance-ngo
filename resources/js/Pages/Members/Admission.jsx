import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

const emptyForm = {
    branch_id: '', samity_id: '', name: '', father_name: '', mother_name: '', spouse_name: '', gender: 'Female',
    religion: '', blood_group: '', marital_status: '', birth_date: '', mobile: '', emergency_phone: '',
    email: '', national_id: '', passport_no: '', tin: '', occupation: '', education: '',
    present_address: '', permanent_address: '', share_amount: '', open_date: '',
    nominee_name: '', nominee_dob: '', nominee_address: '', nominee_phone: '', nominee_relation: '',
    profile_photo_file: null, nominee_photo_file: null,
    // Admission account choice (client round, 2026-11-19): which
    // savings accounts open with the membership. Create-only — the
    // edit form never shows or posts these.
    account_type: 'general', savings_amount: 20, lts_amount: '', lts_term_years: '',
};

/** Member admission (Step 8): tabbed Basic / Other / Pictures + profile panel. */
export default function MemberAdmission({ options, businessDate, member }) {
    const { props } = usePage();
    const editing = !!member;
    const [tab, setTab] = useState('basic');
    const [modalDismissed, setModalDismissed] = useState(false);
    const initial = { ...emptyForm, open_date: businessDate ?? '', branch_id: options.activeBranchId ?? '' };
    if (member) {
        Object.keys(emptyForm).forEach((k) => {
            let v = member[k] ?? '';
            if ((k === 'birth_date' || k === 'open_date') && typeof v === 'string') v = v.slice(0, 10);
            initial[k] = v;
        });
        initial.profile_photo_file = null;
        initial.nominee_photo_file = null;
    }
    const { data, setData, post, processing, errors } = useForm(initial);

    // Edit submits via router.post (not useForm's post): track its flight
    // via callbacks so the submit button still disables + spins (the
    // useForm `processing` flag only covers the create path).
    const [saving, setSaving] = useState(false);
    const submit = (e) => {
        e.preventDefault();
        const opts = {
            preserveScroll: true, forceFormData: true,
            onStart: () => setSaving(true), onFinish: () => setSaving(false),
        };
        if (editing) router.post(`/members/${member.id}`, { ...data, _method: 'put' }, opts);
        else post('/members', opts);
    };

    const text = (key, label, type = 'text', required = false) => (
        <Field label={t(label)} required={required} error={errors[key]}>
            <input type={type} className={inputCls} value={data[key]} onChange={(e) => setData(key, e.target.value)} required={required} />
        </Field>
    );
    const select = (key, opts, label, required = false) => (
        <Field label={t(label)} required={required} error={errors[key]}>
            <select className={inputCls} value={data[key]} onChange={(e) => setData(key, e.target.value)} required={required}>
                <option value="">—</option>
                {opts.map((o) => <option key={o.id} value={o.id}>{o.label_key ? t(o.label_key) : o.name}</option>)}
            </select>
        </Field>
    );
    const area = (key, label) => (
        <Field label={t(label)} error={errors[key]}>
            <textarea className={inputCls} rows={2} value={data[key]} onChange={(e) => setData(key, e.target.value)} />
        </Field>
    );

    // Branch → Samity cascade: the Samity dropdown only offers the
    // chosen branch's samities; changing the branch clears the samity.
    const branchSamities = options.samities.filter((s) => !data.branch_id || String(s.branch_id) === String(data.branch_id));
    const pickBranch = (v) => setData({ ...data, branch_id: v, samity_id: '' });

    const showModal = !editing && props.flash?.created_member_no && !modalDismissed;

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{editing ? t('edit') : t('member_admission')}</h1>

            {showModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-sm rounded-2xl bg-white p-6 text-center shadow-xl dark:bg-zinc-900">
                        <p className="mb-4 font-medium">{props.flash.success}</p>
                        <PrimaryButton onClick={() => setModalDismissed(true)}>{t('confirm')}</PrimaryButton>
                    </div>
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <div className="mb-4 flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-800">
                        {[['basic', 'basic_information'], ['other', 'other_information'], ['pictures', 'pictures']].map(([key, label]) => (
                            <button key={key} type="button" onClick={() => setTab(key)}
                                className={`min-h-[44px] whitespace-nowrap px-3 py-2 text-sm font-medium ${tab === key ? 'border-b-2 border-emerald-600 text-emerald-700 dark:text-emerald-400' : 'text-zinc-500'}`}>
                                {t(label)}
                            </button>
                        ))}
                    </div>

                    <form onSubmit={submit}>
                        {tab === 'basic' && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                {text('name', 'full_name', 'text', true)}
                                <Field label={t('branch')} required error={errors.branch_id}>
                                    <select className={inputCls} value={data.branch_id} onChange={(e) => pickBranch(e.target.value)} required>
                                        <option value="">—</option>
                                        {options.branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                                    </select>
                                </Field>
                                {select('samity_id', branchSamities, 'samity', true)}
                                {text('share_amount', 'share_amount', 'number')}
                                {text('open_date', 'open_date', 'date', true)}
                                {text('father_name', 'father_name')}
                                {text('mother_name', 'mother_name')}
                                {text('spouse_name', 'spouse_name')}
                                {select('gender', options.genders, 'gender', true)}
                                {text('birth_date', 'date_of_birth', 'date')}
                                {text('mobile', 'mobile', 'text', true)}
                                {text('emergency_phone', 'emergency_phone')}
                                {text('email', 'email', 'email')}
                                {text('national_id', 'national_id')}
                                {text('passport_no', 'passport_no')}
                                {text('tin', 'tin')}
                                {!editing && (
                                    <>
                                        <Field label={t('admission_account_type')} required error={errors.account_type}>
                                            <select className={inputCls} value={data.account_type} onChange={(e) => setData('account_type', e.target.value)} required>
                                                <option value="general">{t('account_general_only')}</option>
                                                <option value="both">{t('account_general_and_lts')}</option>
                                            </select>
                                        </Field>
                                        <Field label={t('savings_amount')} required error={errors.savings_amount}>
                                            <input type="number" min="20" step="0.01" className={inputCls} value={data.savings_amount} onChange={(e) => setData('savings_amount', e.target.value)} required />
                                        </Field>
                                        {data.account_type === 'both' && (
                                            <>
                                                <Field label={t('daily_amount')} required error={errors.lts_amount}>
                                                    <select className={inputCls} value={data.lts_amount} onChange={(e) => setData('lts_amount', e.target.value)} required>
                                                        <option value="">—</option>
                                                        {(options.ltsAmounts ?? []).map((a) => <option key={a} value={a}>{a}</option>)}
                                                    </select>
                                                </Field>
                                                <Field label={t('term')} required error={errors.lts_term_years}>
                                                    <select className={inputCls} value={data.lts_term_years} onChange={(e) => setData('lts_term_years', e.target.value)} required>
                                                        <option value="">—</option>
                                                        {(options.ltsTerms ?? []).map((y) => <option key={y} value={y}>{y} {t('years')}</option>)}
                                                    </select>
                                                </Field>
                                            </>
                                        )}
                                    </>
                                )}
                            </div>
                        )}
                        {tab === 'other' && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                {select('religion', options.religions, 'religion')}
                                {select('blood_group', options.bloodGroups, 'blood_group')}
                                {select('marital_status', options.marital, 'marital_status')}
                                {text('occupation', 'occupation')}
                                {text('education', 'education')}
                                {area('present_address', 'present_address')}
                                {area('permanent_address', 'permanent_address')}
                                <div className="sm:col-span-2">
                                    <h3 className="mb-2 font-medium">{t('nominee')}</h3>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        {text('nominee_name', 'nominee_name')}
                                        {text('nominee_dob', 'nominee_dob', 'date')}
                                        {text('nominee_phone', 'nominee_phone')}
                                        {text('nominee_relation', 'nominee_relation')}
                                        {area('nominee_address', 'nominee_address')}
                                    </div>
                                </div>
                            </div>
                        )}
                        {tab === 'pictures' && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label={t('profile_photo')} error={errors.profile_photo_file}>
                                    <input type="file" accept="image/jpeg,image/png" className={inputCls}
                                        onChange={(e) => setData('profile_photo_file', e.target.files?.[0] ?? null)} />
                                </Field>
                                <Field label={t('nominee_photo')} error={errors.nominee_photo_file}>
                                    <input type="file" accept="image/jpeg,image/png" className={inputCls}
                                        onChange={(e) => setData('nominee_photo_file', e.target.files?.[0] ?? null)} />
                                </Field>
                                {member?.photo_url && <img src={member.photo_url} alt="" className="h-24 w-24 rounded-lg object-cover" />}
                                {member?.nominee_photo_url && <img src={member.nominee_photo_url} alt="" className="h-24 w-24 rounded-lg object-cover" />}
                            </div>
                        )}
                        <div className="mt-6">
                            <PrimaryButton type="submit" loading={processing || saving}>{editing ? t('save') : t('create_member')}</PrimaryButton>
                        </div>
                    </form>
                </Card>

                {/* MEMBER PROFILE snapshot panel */}
                <Card>
                    <h2 className="mb-3 font-medium">{t('member_profile')}</h2>
                    <dl className="space-y-2 text-sm">
                        <div><dt className="text-zinc-500">{t('full_name')}</dt><dd className="font-medium">{data.name || '—'}</dd></div>
                        <div><dt className="text-zinc-500">{t('branch')}</dt><dd>{options.branches.find((b) => String(b.id) === String(data.branch_id))?.name ?? '—'}</dd></div>
                        <div><dt className="text-zinc-500">{t('samity')}</dt><dd>{options.samities.find((s) => String(s.id) === String(data.samity_id))?.name ?? '—'}</dd></div>
                        <div><dt className="text-zinc-500">{t('mobile')}</dt><dd>{data.mobile || '—'}</dd></div>
                        <div><dt className="text-zinc-500">{t('share_amount')}</dt><dd>{data.share_amount || '—'}</dd></div>
                        <div><dt className="text-zinc-500">{t('national_id')}</dt><dd>{data.national_id || '—'}</dd></div>
                        {member && <div><dt className="text-zinc-500">{t('member_no')}</dt><dd className="font-medium">{member.member_no}</dd></div>}
                    </dl>
                </Card>
            </div>
        </AppLayout>
    );
}
