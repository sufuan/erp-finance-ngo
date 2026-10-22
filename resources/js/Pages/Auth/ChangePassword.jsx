import { useForm } from '@inertiajs/react';
import AuthLayout from '../../Layouts/AuthLayout';
import { Field, PrimaryButton, inputCls } from '../../Components/ui';
import { t } from '../../lib/i18n';

export default function ChangePassword() {
    const { data, setData, post, processing, errors } = useForm({ current_password: '', password: '', password_confirmation: '' });

    const submit = (e) => {
        e.preventDefault();
        post('/password/change');
    };

    return (
        <AuthLayout title={t('change_password')}>
            <p className="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{t('must_change_password_notice')}</p>
            <form onSubmit={submit} className="space-y-4">
                <Field label={t('current_password')} error={errors.current_password}>
                    <input type="password" className={inputCls} value={data.current_password} onChange={(e) => setData('current_password', e.target.value)} autoComplete="current-password" required />
                </Field>
                <Field label={t('new_password')} error={errors.password}>
                    <input type="password" className={inputCls} value={data.password} onChange={(e) => setData('password', e.target.value)} autoComplete="new-password" required />
                </Field>
                <Field label={t('confirm_password')} error={errors.password_confirmation}>
                    <input type="password" className={inputCls} value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} autoComplete="new-password" required />
                </Field>
                <PrimaryButton type="submit" loading={processing}>{t('change_password')}</PrimaryButton>
            </form>
        </AuthLayout>
    );
}
