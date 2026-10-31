import { router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { Card, Field, PrimaryButton, SelectInput, TextInput } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/**
 * Group Transaction: picking a ledger group loads its template through
 * a server round-trip, and the amount inputs SYNC FROM THE LOADED
 * GROUP PROP via useEffect. (Mount-time capture of the selected group
 * is the stale-state bug class fixed in PassbookIssue/Share — the
 * lines must always reflect the group the clerk just picked.)
 */
export default function GroupTransaction({ groups = [], group = null }) {
    const [selectedId, setSelectedId] = useState(group?.id ? String(group.id) : '');
    const { data, setData, post, processing, errors } = useForm({ group_id: '', amounts: {}, comments: '' });

    useEffect(() => {
        if (group) {
            setSelectedId(String(group.id));
            setData('group_id', group.id);
            setData('amounts', Object.fromEntries(group.lines.map((l) => [l.id, ''])));
        }
    }, [group]);

    const pick = (id) => {
        setSelectedId(id);
        if (id) {
            router.get('/accounts/group-transaction', { group_id: id }, { preserveState: true, preserveScroll: true });
        }
    };

    const totals = useMemo(() => {
        if (!group) return { debit: 0, credit: 0 };
        return group.lines.reduce((acc, l) => {
            const amount = parseFloat(data.amounts[l.id]) || 0;
            return { debit: acc.debit + (l.side === 'Debit' ? amount : 0), credit: acc.credit + (l.side === 'Credit' ? amount : 0) };
        }, { debit: 0, credit: 0 });
    }, [group, data.amounts]);
    const balanced = group && Math.abs(totals.debit - totals.credit) < 0.005 && totals.debit > 0;

    const submit = (e) => {
        e.preventDefault();
        post('/accounts/group-transaction', { preserveScroll: true });
    };

    return (
        <AppLayout>
            <div className="mx-auto max-w-3xl space-y-4 p-4">
                <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t('acc_group_transaction')}</h1>
                <Card className="p-4">
                    <Field label={t('acc_ledger_group_select')} required>
                        <SelectInput value={selectedId} onChange={(e) => pick(e.target.value)}>
                            <option value="">{t('select')}</option>
                            {groups.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
                        </SelectInput>
                    </Field>
                </Card>
                {group && (
                    <Card className="p-4">
                        <form onSubmit={submit} className="space-y-3">
                            {group.lines.map((line) => (
                                <div key={line.id} className="grid grid-cols-12 items-end gap-2">
                                    <div className="col-span-7 text-sm text-slate-700 dark:text-slate-200">
                                        {line.ledger}
                                        <span className="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-slate-700">{line.side === 'Debit' ? t('debit') : t('credit')}</span>
                                    </div>
                                    <div className="col-span-5">
                                        <Field label="" error={errors[`amounts.${line.id}`]}>
                                            <TextInput type="number" step="0.01" min="0" value={data.amounts[line.id] ?? ''}
                                                onChange={(e) => setData('amounts', { ...data.amounts, [line.id]: e.target.value })} />
                                        </Field>
                                    </div>
                                </div>
                            ))}
                            {errors.amounts && <p className="text-sm text-rose-600">{errors.amounts}</p>}
                            <p className={`text-sm font-medium ${balanced ? 'text-emerald-600' : 'text-rose-600'}`}>
                                {t('debit')}: {totals.debit.toFixed(2)} · {t('credit')}: {totals.credit.toFixed(2)}
                                {balanced ? ` ✓ ${t('acc_balanced')}` : ` — ${t('acc_not_balanced')}`}
                            </p>
                            <Field label={t('narration')} error={errors.comments}>
                                <TextInput value={data.comments} onChange={(e) => setData('comments', e.target.value)} />
                            </Field>
                            <PrimaryButton type="submit" loading={processing} disabled={processing || !balanced}>{t('save')}</PrimaryButton>
                        </form>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
