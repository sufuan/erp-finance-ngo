import { useForm } from '@inertiajs/react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Other account opening (Step 10) — generic GL-style accounts. */
export default function OtherOpening({ accounts, businessDate }) {
    const { data, setData, post, processing, errors } = useForm({
        account_type: '', name: '', account_number: '', opening_date: businessDate ?? '', balance: '', comments: '',
    });
    const submit = (e) => { e.preventDefault(); post('/deposits/other', { preserveScroll: true }); };
    const text = (key, label, type = 'text', required = false) => (
        <Field label={t(label)} required={required} error={errors[key]}>
            <input type={type} className={inputCls} value={data[key]} onChange={(e) => setData(key, e.target.value)} required={required} />
        </Field>
    );

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('other_account_opening')}</h1>
            <Card className="mb-6">
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    {text('account_type', 'account_type', 'text', true)}
                    {text('name', 'account_name', 'text', true)}
                    {text('account_number', 'account_number')}
                    {text('opening_date', 'opening_date', 'date', true)}
                    {text('balance', 'balance', 'number', true)}
                    <Field label={t('comments')} required error={errors.comments}>
                        <textarea className={inputCls} rows={2} value={data.comments} onChange={(e) => setData('comments', e.target.value)} required />
                    </Field>
                    <div className="sm:col-span-2">
                        <PrimaryButton type="submit" loading={processing}>{t('save')}</PrimaryButton>
                    </div>
                </form>
            </Card>
            <Card>
                <ResponsiveTable head={[t('account_type'), t('account_name'), t('account_number'), t('opening_date'), t('balance'), t('comments')]}>
                    {accounts.map((a) => (
                        <tr key={a.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{a.account_type}</td>
                            <td className="px-3 py-2">{a.name}</td>
                            <td className="px-3 py-2">{a.account_number}</td>
                            <td className="px-3 py-2">{a.opening_date}</td>
                            <td className="px-3 py-2">{a.balance}</td>
                            <td className="px-3 py-2">{a.comments ?? '—'}</td>
                        </tr>
                    ))}
                    {accounts.length === 0 && <tr><td className="px-3 py-3" colSpan={6}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
