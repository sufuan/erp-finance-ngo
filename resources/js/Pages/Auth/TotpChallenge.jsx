import { useForm } from '@inertiajs/react';
import AuthLayout from '../../Layouts/AuthLayout';
import { Field, PrimaryButton, inputCls } from '../../Components/ui';
import { t } from '../../lib/i18n';

export default function TotpChallenge() {
    const { data, setData, post, processing, errors } = useForm({ code: '' });

    const submit = (e) => {
        e.preventDefault();
        post('/totp/challenge');
    };

    return (
        <AuthLayout title={t('two_factor_authentication')}>
            <p className="mb-4 text-sm text-zinc-600 dark:text-zinc-300">{t('two_factor_challenge_instructions')}</p>
            <form onSubmit={submit} className="space-y-4">
                <Field label={t('two_factor_code')} error={errors.code}>
                    <input className={inputCls} inputMode="numeric" autoComplete="one-time-code" value={data.code} onChange={(e) => setData('code', e.target.value)} required />
                </Field>
                <PrimaryButton type="submit" loading={processing}>{t('verify')}</PrimaryButton>
            </form>
        </AuthLayout>
    );
}
