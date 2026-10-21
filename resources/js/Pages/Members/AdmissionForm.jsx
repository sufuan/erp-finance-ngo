import { t } from '../../lib/i18n';

/** Printable admission form (Step 8) — plain paper layout, no app shell. */
export default function AdmissionForm({ member, samity, branch, nominee, org }) {
    const row = (label, value) => (
        <tr>
            <td style={{ border: '1px solid #999', padding: '6px 10px', width: '30%', background: '#f4f4f5' }}>{t(label)}</td>
            <td style={{ border: '1px solid #999', padding: '6px 10px' }}>{value ?? '—'}</td>
        </tr>
    );

    return (
        <div style={{ maxWidth: 800, margin: '0 auto', padding: 24, fontFamily: '"Hind Siliguri", sans-serif', color: '#111', background: '#fff', lineHeight: 1.7 }}>
            <div style={{ textAlign: 'center', marginBottom: 16 }}>
                <div style={{ fontSize: 20, fontWeight: 700 }}>{org?.name_bn ?? org?.name_en ?? ''}</div>
                <div style={{ fontSize: 16 }}>{org?.name_en ?? ''}</div>
                <div style={{ fontSize: 15, fontWeight: 600, marginTop: 6 }}>{t('admission_form')}</div>
            </div>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
                <tbody>
                    {row('member_no', member.member_no)}
                    {row('full_name', member.name)}
                    {row('samity', samity ? `${samity.samity_code} — ${samity.name}` : '—')}
                    {row('branch', branch ? `${branch.code} — ${branch.name}` : '—')}
                    {row('father_name', member.father_name)}
                    {row('mother_name', member.mother_name)}
                    {row('spouse_name', member.spouse_name)}
                    {row('gender', member.gender)}
                    {row('date_of_birth', member.birth_date ? String(member.birth_date).slice(0, 10) : null)}
                    {row('mobile', member.mobile)}
                    {row('national_id', member.national_id)}
                    {row('religion', member.religion)}
                    {row('blood_group', member.blood_group)}
                    {row('occupation', member.occupation)}
                    {row('present_address', member.present_address)}
                    {row('permanent_address', member.permanent_address)}
                    {row('share_amount', member.share_amount)}
                    {row('open_date', member.open_date ? String(member.open_date).slice(0, 10) : null)}
                    {row('nominee_name', nominee?.name)}
                    {row('nominee_relation', nominee?.relation)}
                    {row('nominee_phone', nominee?.phone)}
                </tbody>
            </table>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 48, fontSize: 13 }}>
                <span>____________________<br />{t('member_signature')}</span>
                <span>____________________<br />{t('officer_signature')}</span>
            </div>
            <button onClick={() => window.print()} className="print:hidden" style={{ marginTop: 24, padding: '10px 18px', background: '#059669', color: '#fff', borderRadius: 8, border: 'none', cursor: 'pointer' }}>
                {t('print')}
            </button>
        </div>
    );
}
