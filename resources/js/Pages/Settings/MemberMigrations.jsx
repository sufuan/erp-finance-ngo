import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Download, FileSpreadsheet, Sheet } from 'lucide-react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import ExcelImport from '../../Components/ExcelImport';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

const GRID_COLS = [
    ['member_id', 'member_no'], ['name', 'full_name'], ['samity', 'samity'], ['gender', 'gender'],
    ['father_name', 'fathers_husbands_name'], ['mobile', 'mobile'], ['present_address', 'present_address'],
    ['admission_date', 'open_date'], ['national_id', 'national_id'], ['primary_product', 'primary_product'],
];

const EXCEL_ENDPOINTS = {
    preview: '/settings/member-migrations/preview',
    validate: '/settings/member-migrations/validate-rows',
    confirm: '/settings/member-migrations/confirm-import',
};

/**
 * Member Migrations — runs on the reusable Excel engine (2026-10-22):
 * template download, member export, and the editable red/green preview.
 * Below it, the Step 9A staged-batch preview (for history batches made
 * by the old flow) and the migration history stay as they were.
 */
export default function MemberMigrations({ samities, batches, preview, excelColumns }) {
    const [samityId, setSamityId] = useState('');
    const [confirming, setConfirming] = useState(false);
    const confirmLegacy = () => router.post(`/settings/member-migrations/${preview.id}/confirm`, {}, {
        onStart: () => setConfirming(true), onFinish: () => setConfirming(false),
    });

    const rawCell = (raw, key) => (raw?.[key] === undefined || raw?.[key] === '' ? '—' : String(raw[key]));
    const actionBtn = 'inline-flex min-h-[44px] items-center gap-2 rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800';

    return (
        <AppLayout>
            <div className="mb-4 flex flex-wrap items-start gap-3">
                <div className="min-w-[240px] flex-1">
                    <h1 className="text-xl font-semibold">{t('member_migrations')}</h1>
                    <p className="mt-1 max-w-2xl text-sm text-zinc-500">{t('excel_engine_intro')}</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <a href="/settings/member-migrations/template" className={actionBtn}>
                        <Download className="h-4 w-4" /> {t('download_template')}
                    </a>
                    <a href="/settings/member-migrations/export" className={actionBtn}>
                        <Sheet className="h-4 w-4" /> {t('export_excel')}
                    </a>
                </div>
            </div>

            <Card>
                <h2 className="mb-3 inline-flex items-center gap-2 font-medium">
                    <FileSpreadsheet className="h-5 w-5 text-emerald-600 dark:text-emerald-400" /> {t('excel_import')}
                </h2>
                <div className="mb-4 max-w-md">
                    <Field label={t('excel_default_samity')}>
                        <select className={inputCls} value={samityId} onChange={(e) => setSamityId(e.target.value)}>
                            <option value="">—</option>
                            {samities.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
                        </select>
                    </Field>
                </div>
                <ExcelImport
                    columns={excelColumns ?? []}
                    endpoints={EXCEL_ENDPOINTS}
                    context={{ samity_id: samityId === '' ? null : Number(samityId) }}
                    onImported={() => router.reload({ only: ['batches'] })}
                />
            </Card>

            {preview && (
                <Card className="mt-4">
                    <div className="mb-3 flex flex-wrap items-center gap-3">
                        <h2 className="font-medium">{t('preview')}</h2>
                        <span className="text-sm text-zinc-500">
                            {t('total_rows')}: {preview.total} · {t('ok')}: {preview.ok_count} · {t('errors')}: {preview.error_count}
                        </span>
                        {preview.status === 'Preview' && (
                            <PrimaryButton onClick={confirmLegacy} loading={confirming} className="ml-auto">{t('confirm_import')}</PrimaryButton>
                        )}
                    </div>
                    <ResponsiveTable head={[t('sl'), ...GRID_COLS.map(([, label]) => t(label)), t('status')]}>
                        {preview.rows.map((r) => (
                            <tr key={r.no} className="border-b border-zinc-100 dark:border-zinc-800">
                                <td className="px-3 py-2">{r.no}</td>
                                {GRID_COLS.map(([key]) => <td key={key} className="px-3 py-2">{rawCell(r.raw, key)}</td>)}
                                <td className="px-3 py-2">
                                    {r.errors.length === 0
                                        ? <span className="text-emerald-600 dark:text-emerald-400">{t('ok')}</span>
                                        : <span className="text-red-600 dark:text-red-400">{r.errors.join('; ')}</span>}
                                </td>
                            </tr>
                        ))}
                        {preview.rows.length === 0 && <tr><td className="px-3 py-3" colSpan={GRID_COLS.length + 2}>{t('no_data_found')}</td></tr>}
                    </ResponsiveTable>
                </Card>
            )}

            <Card className="mt-4">
                <h2 className="mb-3 font-medium">{t('history')}</h2>
                <ResponsiveTable head={[t('date'), t('created_by'), t('source'), t('status'), t('total_rows'), t('imported'), t('failed'), t('actions')]}>
                    {batches.map((b) => (
                        <tr key={b.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{String(b.created_at).slice(0, 10)}</td>
                            <td className="px-3 py-2">{b.created_by ?? '—'}</td>
                            <td className="px-3 py-2">{t(b.source === 'excel' ? 'source_excel' : 'source_grid')}</td>
                            <td className="px-3 py-2">{t(`status_${b.status.toLowerCase()}`)}</td>
                            <td className="px-3 py-2">{b.total_rows}</td>
                            <td className="px-3 py-2">{b.imported_count}</td>
                            <td className="px-3 py-2">{b.failed_count}</td>
                            <td className="px-3 py-2">
                                <span className="flex flex-wrap gap-2">
                                    <Link href={`/settings/member-migrations/${b.id}`} className="text-emerald-700 dark:text-emerald-400">{t('preview')}</Link>
                                    {b.failed_count > 0 && <a href={`/settings/member-migrations/${b.id}/error-report`} className="text-red-600 dark:text-red-400">{t('error_report')}</a>}
                                </span>
                            </td>
                        </tr>
                    ))}
                    {batches.length === 0 && <tr><td className="px-3 py-3" colSpan={8}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
