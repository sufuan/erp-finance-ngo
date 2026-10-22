import { useForm } from '@inertiajs/react';
import AuthLayout from '../../Layouts/AuthLayout';
import { Field, PrimaryButton, inputCls } from '../../Components/ui';
import { t } from '../../lib/i18n';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({ username: '', password: '' });

    const submit = (e) => {
        e.preventDefault();
        post('/login');
    };

    return (
        <AuthLayout title={t('login')}>
            <form onSubmit={submit} className="space-y-4">
                <Field label={t('username')} error={errors.username}>
                    <input className={inputCls} value={data.username} onChange={(e) => setData('username', e.target.value)} autoComplete="username" required />
                </Field>
                <Field label={t('password')} error={errors.password}>
                    <input type="password" className={inputCls} value={data.password} onChange={(e) => setData('password', e.target.value)} autoComplete="current-password" required />
                </Field>
                <PrimaryButton type="submit" loading={processing}>{t('login')}</PrimaryButton>
            </form>
        </AuthLayout>
    );
}
