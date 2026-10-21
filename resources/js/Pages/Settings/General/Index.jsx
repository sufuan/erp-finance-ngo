import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Field, PrimaryButton, inputCls } from '../../../Components/ui';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

/**
 * General Settings (Step 3): one form, tabbed sections. Every value is
 * dynamic — app name, rates, fees, GPS — stored via SettingsService.
 */
// Shape guards: a stored setting can arrive in an unexpected shape (older
// build, manual DB edit). Array/object operations on a string used to throw
// during render and white-screen the whole page — coerce instead.
const asArray = (v) => (Array.isArray(v) ? v : []);
const asObject = (v) => (v && typeof v === 'object' && !Array.isArray(v) ? v : {});

export default function GeneralSettings({ profile, settings }) {
    const [tab, setTab] = useState('organization');
    const s = settings ?? {};

    const { data, setData, post, processing, errors } = useForm({
        profile: {
            name_en: profile?.name_en ?? '', name_bn: profile?.name_bn ?? '',
            org_type: profile?.org_type ?? '', registration_no: profile?.registration_no ?? '',
            mobile_app_name: profile?.mobile_app_name ?? '', contact_person: profile?.contact_person ?? '',
            address: profile?.address ?? '', phone: profile?.phone ?? '', email: profile?.email ?? '',
        },
        logo: null,
        settings: {
            'app.name': s['app.name'] ?? '',
            'app.locale_default': s['app.locale_default'] ?? 'bn',
            'locale.number_system': s['locale.number_system'] ?? 'arabic',
            'locale.date_format': s['locale.date_format'] ?? 'd-M-Y',
            'print.header_lines': asArray(s['print.header_lines']).join('\n'),
            'print.footer_text': s['print.footer_text'] ?? '',
            'loan.default_profit_rate': s['loan.default_profit_rate'] ?? 15,
            'loan.max_service_charge_rate': s['loan.max_service_charge_rate'] ?? 24,
            'loan.allocation_order': asArray(s['loan.allocation_order']).join(', '),
            'loan.allow_overpayment': !!s['loan.allow_overpayment'],
            'loan.enabled_frequencies': Array.isArray(s['loan.enabled_frequencies']) ? s['loan.enabled_frequencies'] : ['D', 'W', 'M'],
            'loan.fee_defaults': { form: 0, health: 0, risk: 0, document: 0, emi_locker: 0, others: 0, ...asObject(s['loan.fee_defaults']) },
            'llp.rates': { standard: 1, watchlist: 5, substandard: 25, doubtful: 75, loss: 100, ...asObject(s['llp.rates']) },
            'llp.basis': s['llp.basis'] ?? 'net_outstanding',
            'profit.tax_withholding_rate': s['profit.tax_withholding_rate'] ?? 0,
            'accounts.auth_amount_threshold': s['accounts.auth_amount_threshold'] ?? 50000,
            'deposit.default_profit_rates': { savings: 0, dps: 0, fdr: 0, ...asObject(s['deposit.default_profit_rates']) },
            'deposit.closing_charge': s['deposit.closing_charge'] ?? 0,
            'sms.sender_id': s['sms.sender_id'] ?? '',
            'sms.gateway': s['sms.gateway'] ?? '',
            'sms.enabled': !!s['sms.enabled'],
            'gps.enabled': !!s['gps.enabled'],
            'gps.interval_seconds': s['gps.interval_seconds'] ?? 60,
            'gps.retention_days': s['gps.retention_days'] ?? 90,
            'app.max_offline_days': s['app.max_offline_days'] ?? 7,
        },
    });

    const set = (key, value) => setData('settings', { ...data.settings, [key]: value });
    const setProfile = (key, value) => setData('profile', { ...data.profile, [key]: value });
    const toggleFreq = (f) => {
        const cur = data.settings['loan.enabled_frequencies'];
        set('loan.enabled_frequencies', cur.includes(f) ? cur.filter((x) => x !== f) : [...cur, f]);
    };

    const submit = (e) => {
        e.preventDefault();
        // Convert text-held JSON fields back to arrays before submit.
        const payload = {
            ...data,
            settings: {
                ...data.settings,
                'print.header_lines': data.settings['print.header_lines'].split('\n').map((x) => x.trim()).filter(Boolean),
                'loan.allocation_order': data.settings['loan.allocation_order'].split(',').map((x) => x.trim()).filter(Boolean),
            },
        };
        post('/settings/general', { data: payload, forceFormData: true, preserveScroll: true });
    };

    const tabs = [
        ['organization', t('organization')],
        ['application', t('application')],
        ['loan', t('loan_and_profit')],
        ['deposit', t('deposit')],
        ['sms', t('sms_and_notifications')],
        ['gps', t('gps_and_tracking')],
    ];

    const num = (label, value, onChange, error) => (
        <Field label={label} error={error}>
            <input type="number" step="any" className={inputCls} value={value} onChange={(e) => onChange(e.target.value === '' ? '' : Number(e.target.value))} />
        </Field>
    );
    const check = (label, value, onChange) => (
        <label className="flex min-h-[44px] items-center gap-2 text-sm">
            <input type="checkbox" className="h-5 w-5" checked={!!value} onChange={(e) => onChange(e.target.checked)} /> {label}
        </label>
    );

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('general_settings')}</h1>
            <form onSubmit={submit} className="rounded-2xl bg-white p-4 shadow dark:bg-zinc-900">
                <div className="mb-5 flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-800">
                    {tabs.map(([key, label]) => (
                        <button key={key} type="button" onClick={() => setTab(key)}
                            className={`min-h-[44px] whitespace-nowrap px-3 py-2 text-sm font-medium ${tab === key ? 'border-b-2 border-emerald-600 text-emerald-700 dark:text-emerald-400' : 'text-zinc-500'}`}>
                            {label}
                        </button>
                    ))}
                </div>

                {tab === 'organization' && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label={`${t('organization')} (English)`} error={errors['profile.name_en']}><input className={inputCls} value={data.profile.name_en} onChange={(e) => setProfile('name_en', e.target.value)} required /></Field>
                        <Field label={`${t('organization')} (বাংলা)`} error={errors['profile.name_bn']}><input className={inputCls} value={data.profile.name_bn} onChange={(e) => setProfile('name_bn', e.target.value)} required /></Field>
                        <Field label={t('org_type')} error={errors['profile.org_type']}><input className={inputCls} value={data.profile.org_type} onChange={(e) => setProfile('org_type', e.target.value)} required /></Field>
                        <Field label={t('registration_no')} error={errors['profile.registration_no']}><input className={inputCls} value={data.profile.registration_no} onChange={(e) => setProfile('registration_no', e.target.value)} required /></Field>
                        <Field label={t('mobile_app_name')} error={errors['profile.mobile_app_name']}><input className={inputCls} value={data.profile.mobile_app_name} onChange={(e) => setProfile('mobile_app_name', e.target.value)} required /></Field>
                        <Field label={t('contact_person')} error={errors['profile.contact_person']}><input className={inputCls} value={data.profile.contact_person} onChange={(e) => setProfile('contact_person', e.target.value)} /></Field>
                        <Field label={t('address')} error={errors['profile.address']}><input className={inputCls} value={data.profile.address} onChange={(e) => setProfile('address', e.target.value)} /></Field>
                        <Field label={t('phone')} error={errors['profile.phone']}><input className={inputCls} value={data.profile.phone} onChange={(e) => setProfile('phone', e.target.value)} /></Field>
                        <Field label={t('email')} error={errors['profile.email']}><input type="email" className={inputCls} value={data.profile.email} onChange={(e) => setProfile('email', e.target.value)} /></Field>
                        <Field label={t('logo')} error={errors.logo}>
                            <input type="file" accept="image/*" className={inputCls} onChange={(e) => setData('logo', e.target.files[0] ?? null)} />
                        </Field>
                        {profile?.logo_path && <img src={`/files/${profile.logo_path}`} alt={t('logo')} className="max-h-20 rounded-lg border border-zinc-200 object-contain dark:border-zinc-700" />}
                    </div>
                )}

                {tab === 'application' && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('app_name')}><input className={inputCls} value={data.settings['app.name']} onChange={(e) => set('app.name', e.target.value)} /></Field>
                        <Field label={t('default_locale')}>
                            <select className={inputCls} value={data.settings['app.locale_default']} onChange={(e) => set('app.locale_default', e.target.value)}>
                                <option value="bn">বাংলা</option><option value="en">English</option>
                            </select>
                        </Field>
                        <Field label={t('number_system')}>
                            <select className={inputCls} value={data.settings['locale.number_system']} onChange={(e) => set('locale.number_system', e.target.value)}>
                                <option value="arabic">{t('arabic_numbers')}</option><option value="bengali">{t('bengali_numbers')}</option>
                            </select>
                        </Field>
                        <Field label={t('date_format')}><input className={inputCls} value={data.settings['locale.date_format']} onChange={(e) => set('locale.date_format', e.target.value)} /></Field>
                        <Field label={t('header_lines')}>
                            <textarea className={inputCls} rows={3} value={data.settings['print.header_lines']} onChange={(e) => set('print.header_lines', e.target.value)} />
                            <span className="mt-1 block text-xs text-zinc-500">{t('one_per_line')}</span>
                        </Field>
                        <Field label={t('footer_text')}><input className={inputCls} value={data.settings['print.footer_text']} onChange={(e) => set('print.footer_text', e.target.value)} /></Field>
                    </div>
                )}

                {tab === 'loan' && (
                    <div className="space-y-5">
                        <div className="grid gap-4 sm:grid-cols-2">
                            {num(t('default_profit_rate'), data.settings['loan.default_profit_rate'], (v) => set('loan.default_profit_rate', v))}
                            {num(t('max_service_charge_rate'), data.settings['loan.max_service_charge_rate'], (v) => set('loan.max_service_charge_rate', v))}
                            <Field label={t('allocation_order')}><input className={inputCls} value={data.settings['loan.allocation_order']} onChange={(e) => set('loan.allocation_order', e.target.value)} /></Field>
                            <Field label={t('llp_basis')}>
                                <select className={inputCls} value={data.settings['llp.basis']} onChange={(e) => set('llp.basis', e.target.value)}>
                                    <option value="net_outstanding">{t('llp_basis_net')}</option>
                                    <option value="gross_outstanding">Gross outstanding</option>
                                </select>
                            </Field>
                            {num(t('tax_withholding_rate'), data.settings['profit.tax_withholding_rate'], (v) => set('profit.tax_withholding_rate', v))}
                            {num(t('auth_amount_threshold'), data.settings['accounts.auth_amount_threshold'], (v) => set('accounts.auth_amount_threshold', v))}
                        </div>
                        <fieldset>
                            <legend className="mb-1 text-sm font-medium">{t('enabled_frequencies')}</legend>
                            <div className="flex flex-wrap gap-4">
                                {check(`${t('frequency_d')} (D)`, data.settings['loan.enabled_frequencies'].includes('D'), () => toggleFreq('D'))}
                                {check(`${t('frequency_w')} (W)`, data.settings['loan.enabled_frequencies'].includes('W'), () => toggleFreq('W'))}
                                {check(`${t('frequency_m')} (M)`, data.settings['loan.enabled_frequencies'].includes('M'), () => toggleFreq('M'))}
                            </div>
                            {check(t('allow_overpayment'), data.settings['loan.allow_overpayment'], (v) => set('loan.allow_overpayment', v))}
                        </fieldset>
                        <fieldset>
                            <legend className="mb-2 text-sm font-medium">{t('fee_defaults')}</legend>
                            <div className="grid gap-4 sm:grid-cols-3">
                                {[['form', 'fee_form'], ['health', 'fee_health'], ['risk', 'fee_risk'], ['document', 'fee_document'], ['emi_locker', 'fee_emi_locker'], ['others', 'fee_others']].map(([k, label]) =>
                                    num(t(label), data.settings['loan.fee_defaults'][k], (v) => set('loan.fee_defaults', { ...data.settings['loan.fee_defaults'], [k]: v }), null, k))}
                            </div>
                        </fieldset>
                        <fieldset>
                            <legend className="mb-2 text-sm font-medium">{t('llp_rates')}</legend>
                            <div className="grid gap-4 sm:grid-cols-3 lg:grid-cols-5">
                                {[['standard', 'class_standard'], ['watchlist', 'class_watchlist'], ['substandard', 'class_substandard'], ['doubtful', 'class_doubtful'], ['loss', 'class_loss']].map(([k, label]) =>
                                    num(t(label), data.settings['llp.rates'][k], (v) => set('llp.rates', { ...data.settings['llp.rates'], [k]: v }), null, k))}
                            </div>
                        </fieldset>
                    </div>
                )}

                {tab === 'deposit' && (
                    <div className="space-y-5">
                        <fieldset>
                            <legend className="mb-2 text-sm font-medium">{t('deposit_profit_rates')}</legend>
                            <div className="grid gap-4 sm:grid-cols-3">
                                {[['savings', 'rate_savings'], ['dps', 'rate_dps'], ['fdr', 'rate_fdr']].map(([k, label]) =>
                                    num(t(label), data.settings['deposit.default_profit_rates'][k], (v) => set('deposit.default_profit_rates', { ...data.settings['deposit.default_profit_rates'], [k]: v }), null, k))}
                            </div>
                        </fieldset>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {num(t('closing_charge'), data.settings['deposit.closing_charge'], (v) => set('deposit.closing_charge', v))}
                        </div>
                    </div>
                )}

                {tab === 'sms' && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('sender_id')}><input className={inputCls} value={data.settings['sms.sender_id']} onChange={(e) => set('sms.sender_id', e.target.value)} /></Field>
                        <Field label={t('sms_gateway')}><input className={inputCls} value={data.settings['sms.gateway']} onChange={(e) => set('sms.gateway', e.target.value)} /></Field>
                        {check(t('sms_enabled'), data.settings['sms.enabled'], (v) => set('sms.enabled', v))}
                    </div>
                )}

                {tab === 'gps' && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        {check(t('gps_enabled'), data.settings['gps.enabled'], (v) => set('gps.enabled', v))}
                        {num(t('gps_interval_seconds'), data.settings['gps.interval_seconds'], (v) => set('gps.interval_seconds', v))}
                        {num(t('gps_retention_days'), data.settings['gps.retention_days'], (v) => set('gps.retention_days', v))}
                        {num(t('max_offline_days'), data.settings['app.max_offline_days'], (v) => set('app.max_offline_days', v))}
                    </div>
                )}

                <div className="mt-6 border-t border-zinc-200 pt-4 dark:border-zinc-800">
                    <PrimaryButton type="submit" disabled={processing}>{t('save')}</PrimaryButton>
                </div>
            </form>
        </AppLayout>
    );
}
