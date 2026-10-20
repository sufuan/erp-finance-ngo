import { useForm } from '@inertiajs/react';
import AuthLayout from '../../Layouts/AuthLayout';
import { Field, PrimaryButton, inputCls } from '../../Components/ui';
import { t } from '../../lib/i18n';

export default function TotpSetup({ secret, qrSvg }) {
    const { data, setData, post, processing, errors } = useForm({ code: '' });

    const submit = (e) => {
        e.preventDefault();
        post('/totp/setup');
    };

    return (
        <AuthLayout title={t('two_factor_authentication')}>
            <p className="mb-4 text-sm text-zinc-600 dark:text-zinc-300">{t('two_factor_setup_instructions')}</p>
            <div className="mb-4 flex justify-center rounded-lg bg-white p-3" dangerouslySetInnerHTML={{ __html: qrSvg }} />
            <p className="mb-4 break-all text-center text-sm">
                <span className="text-zinc-500">{t('manual_setup_key')}: </span>
                <span className="font-mono font-medium">{secret}</span>
            </p>
            <form onSubmit={submit} className="space-y-4">
                <Field label={t('two_factor_code')} error={errors.code}>
                    <input className={inputCls} inputMode="numeric" autoComplete="one-time-code" value={data.code} onChange={(e) => setData('code', e.target.value)} required />
                </Field>
                <PrimaryButton type="submit" disabled={processing}>{t('verify')}</PrimaryButton>
            </form>
        </AuthLayout>
    );
}
