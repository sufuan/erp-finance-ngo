import { router, useForm } from '@inertiajs/react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../../Components/ui';
import AppLayout from '../../../Layouts/AppLayout';
import { t } from '../../../lib/i18n';

/** Notice setup (Step 7) — the latest notice renders in the topbar. */
export default function Notices({ notices, colors }) {
    const { data, setData, post, processing, errors } = useForm({ text: '', text_color: 'Black' });
    const submit = (e) => { e.preventDefault(); post('/settings/notices', { preserveScroll: true, onSuccess: () => setData('text', '') }); };
    const colorCls = (c) => c === 'Red' ? 'bg-red-600 text-white' : c === 'Green' ? 'bg-emerald-600 text-white' : c === 'Yellow' ? 'bg-yellow-300 text-zinc-900' : 'bg-zinc-900 text-white';

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('notices')}</h1>

            <Card className="mb-6">
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label={`${t('notice_text')} *`} error={errors.text}>
                        <input className={inputCls} value={data.text} onChange={(e) => setData('text', e.target.value)} required maxLength={500} />
                    </Field>
                    <Field label={`${t('text_color')} *`} error={errors.text_color}>
                        <select className={inputCls} value={data.text_color} onChange={(e) => setData('text_color', e.target.value)}>
                            {colors.map((c) => <option key={c} value={c}>{t(`color_${c.toLowerCase()}`)}</option>)}
                        </select>
                    </Field>
                    <div className="sm:col-span-2">
                        <div className={`rounded-lg px-4 py-2 text-center text-sm font-medium ${colorCls(data.text_color)}`}>
                            {data.text || t('notice_preview')}
                        </div>
                    </div>
                    <div className="sm:col-span-2">
                        <PrimaryButton type="submit" disabled={processing}>{t('save')}</PrimaryButton>
                    </div>
                </form>
            </Card>

            <Card>
                <ResponsiveTable head={[t('notice_text'), t('text_color'), t('date'), t('actions')]}>
                    {notices.map((n) => (
                        <tr key={n.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{n.text}</td>
                            <td className="px-3 py-2">{t(`color_${String(n.text_color).toLowerCase()}`)}</td>
                            <td className="px-3 py-2">{String(n.created_at).slice(0, 10)}</td>
                            <td className="px-3 py-2">
                                <button type="button" onClick={() => router.delete(`/settings/notices/${n.id}`, { preserveScroll: true })} className="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">{t('delete')}</button>
                            </td>
                        </tr>
                    ))}
                    {notices.length === 0 && <tr><td className="px-3 py-3" colSpan={4}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
