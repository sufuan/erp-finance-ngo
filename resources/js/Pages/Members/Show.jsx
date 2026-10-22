import { Link, useForm } from '@inertiajs/react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Member 360 details (Step 8): profile, nominee, documents, visits. */
export default function MemberShow({ member, nominees, documents, visits, docTypes }) {
    const docForm = useForm({ doc_type: 'NID', document: null });
    const visitForm = useForm({ visit_date: '', info: '', latitude: '', longitude: '' });

    const submitDoc = (e) => { e.preventDefault(); docForm.post(`/members/${member.id}/documents`, { forceFormData: true, preserveScroll: true }); };
    const submitVisit = (e) => { e.preventDefault(); visitForm.post(`/members/${member.id}/visits`, { preserveScroll: true }); };

    const item = (label, value) => (
        <div>
            <dt className="text-zinc-500">{t(label)}</dt>
            <dd className="font-medium">{value === null || value === undefined || value === '' ? '—' : String(value)}</dd>
        </div>
    );

    return (
        <AppLayout>
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <h1 className="text-xl font-semibold">{t('member_details')}</h1>
                <div className="ml-auto flex gap-2">
                    <Link href={`/members/${member.id}/edit`} className="min-h-[44px] rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700">{t('edit')}</Link>
                    <Link href={`/members/${member.id}/admission-form`} className="min-h-[44px] rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700">{t('admission_form')}</Link>
                </div>
            </div>

            <Card className="mb-4">
                <div className="flex items-center gap-4">
                    {member.photo_url && <img src={member.photo_url} alt="" className="h-20 w-20 rounded-2xl object-cover" />}
                    <div>
                        <div className="text-lg font-semibold">{member.name}</div>
                        <div className="text-sm text-zinc-500">{member.member_no} · {member.samity?.name ?? ''} · {member.branch ? `${member.branch.code} — ${member.branch.name}` : ''}</div>
                        <div className="text-sm text-zinc-500">{t('status')}: {member.status} · {t('username')}: {member.username ?? '—'}</div>
                    </div>
                </div>
                <dl className="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 lg:grid-cols-4">
                    {item('father_name', member.father_name)}
                    {item('mother_name', member.mother_name)}
                    {item('spouse_name', member.spouse_name)}
                    {item('gender', member.gender)}
                    {item('religion', member.religion)}
                    {item('blood_group', member.blood_group)}
                    {item('marital_status', member.marital_status)}
                    {item('date_of_birth', member.birth_date)}
                    {item('mobile', member.mobile)}
                    {item('emergency_phone', member.emergency_phone)}
                    {item('email', member.email)}
                    {item('national_id', member.national_id)}
                    {item('passport_no', member.passport_no)}
                    {item('tin', member.tin)}
                    {item('occupation', member.occupation)}
                    {item('education', member.education)}
                    {item('share_amount', member.share_amount)}
                    {item('open_date', member.open_date)}
                    {item('present_address', member.present_address)}
                    {item('permanent_address', member.permanent_address)}
                </dl>
            </Card>

            <Card className="mb-4">
                <h2 className="mb-3 font-medium">{t('nominee')}</h2>
                {nominees.map((n) => (
                    <div key={n.id} className="flex items-center gap-4">
                        {n.photo_url && <img src={n.photo_url} alt="" className="h-16 w-16 rounded-xl object-cover" />}
                        <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                            {item('nominee_name', n.name)}
                            {item('nominee_relation', n.relation)}
                            {item('nominee_phone', n.phone)}
                            {item('age', n.age)}
                            {item('nominee_address', n.address)}
                        </dl>
                    </div>
                ))}
                {nominees.length === 0 && <p className="text-sm text-zinc-500">{t('no_data_found')}</p>}
            </Card>

            <Card className="mb-4" >
                <h2 className="mb-3 font-medium" id="documents">{t('documents')}</h2>
                <form onSubmit={submitDoc} className="mb-4 grid gap-3 sm:grid-cols-3">
                    <Field label={t('doc_type')} error={docForm.errors.doc_type}>
                        <select className={inputCls} value={docForm.data.doc_type} onChange={(e) => docForm.setData('doc_type', e.target.value)}>
                            {docTypes.map((d) => <option key={d} value={d}>{d}</option>)}
                        </select>
                    </Field>
                    <Field label={t('choose_file')} error={docForm.errors.document}>
                        <input type="file" className={inputCls} onChange={(e) => docForm.setData('document', e.target.files?.[0] ?? null)} required />
                    </Field>
                    <div className="flex items-end">
                        <PrimaryButton type="submit" loading={docForm.processing}>{t('save')}</PrimaryButton>
                    </div>
                </form>
                <ResponsiveTable head={[t('doc_type'), t('date'), t('actions')]}>
                    {documents.map((d) => (
                        <tr key={d.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{d.doc_type}</td>
                            <td className="px-3 py-2">{String(d.created_at).slice(0, 10)}</td>
                            <td className="px-3 py-2"><a href={d.file_url} className="text-emerald-700 dark:text-emerald-400">{t('download')}</a></td>
                        </tr>
                    ))}
                    {documents.length === 0 && <tr><td className="px-3 py-3" colSpan={3}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>

            <Card>
                <h2 className="mb-3 font-medium" id="visits">{t('visiting_history')}</h2>
                <form onSubmit={submitVisit} className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Field label={t('visit_date')} required error={visitForm.errors.visit_date}>
                        <input type="date" className={inputCls} value={visitForm.data.visit_date} onChange={(e) => visitForm.setData('visit_date', e.target.value)} required />
                    </Field>
                    <Field label={t('visit_info')} error={visitForm.errors.info}>
                        <input className={inputCls} value={visitForm.data.info} onChange={(e) => visitForm.setData('info', e.target.value)} />
                    </Field>
                    <Field label={t('latitude')} error={visitForm.errors.latitude}>
                        <input type="number" step="any" className={inputCls} value={visitForm.data.latitude} onChange={(e) => visitForm.setData('latitude', e.target.value)} />
                    </Field>
                    <Field label={t('longitude')} error={visitForm.errors.longitude}>
                        <input type="number" step="any" className={inputCls} value={visitForm.data.longitude} onChange={(e) => visitForm.setData('longitude', e.target.value)} />
                    </Field>
                    <div className="sm:col-span-2 lg:col-span-4">
                        <PrimaryButton type="submit" loading={visitForm.processing}>{t('add_visiting_info')}</PrimaryButton>
                    </div>
                </form>
                <ResponsiveTable head={[t('visit_date'), t('visit_info'), t('latitude'), t('longitude'), t('check_in')]}>
                    {visits.map((v) => (
                        <tr key={v.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{String(v.visit_date).slice(0, 10)}</td>
                            <td className="px-3 py-2">{v.info ?? '—'}</td>
                            <td className="px-3 py-2">{v.latitude ?? '—'}</td>
                            <td className="px-3 py-2">{v.longitude ?? '—'}</td>
                            <td className="px-3 py-2">{v.check_in_at ? String(v.check_in_at).replace('T', ' ').slice(0, 16) : '—'}</td>
                        </tr>
                    ))}
                    {visits.length === 0 && <tr><td className="px-3 py-3" colSpan={5}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
