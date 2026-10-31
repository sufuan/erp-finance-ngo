import { useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { Card, Field, PrimaryButton, SelectInput, TextInput } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

const TITLES = {
    account: 'acc_account_transaction',
    ledger: 'acc_ledger_transaction',
    journal: 'acc_journal_entry',
    contra: 'acc_contra_voucher',
};
const ROUTES = {
    account: '/accounts/transaction',
    ledger: '/accounts/ledger-transaction',
    journal: '/accounts/journal',
    contra: '/accounts/contra',
};

/**
 * The four financial-transaction forms (Step 26). Ledger options come
 * from the server (manual, non-control ledgers only). On the Account
 * Transaction form, picking a Receive/Payment Setup preset applies its
 * side + ledger to the form — the selection SYNCs FROM THE SETUP PROP
 * via useEffect (lookup-driven form; mount-time capture is the
 * stale-state bug class fixed in PassbookIssue/Share).
 */
export default function TransactionForm({ kind, ledgers = [], setups = [], businessDate = null, mode = 'Receive', setupId = null }) {
    const isJournal = kind === 'journal';
    const { data, setData, post, processing, errors } = useForm({
        side: mode, ledger_id: '', setup_id: '', source: 'cash', amount: '', comments: '',
        debit_ledger_id: '', credit_ledger_id: '',
        lines: [{ ledger_id: '', debit: '', credit: '' }, { ledger_id: '', debit: '', credit: '' }],
        direction: 'cash_to_bank',
    });

    const [setupPick, setSetupPick] = useState(setupId ? String(setupId) : '');
    useEffect(() => {
        const setup = setups.find((s) => String(s.id) === String(setupPick));
        if (setup) {
            setData('setup_id', setup.id);
            setData('side', setup.side);
            setData('ledger_id', setup.ledger_id);
        }
    }, [setupPick, setups]);

    const journalTotals = useMemo(() => data.lines.reduce((acc, l) => ({
        debit: acc.debit + (parseFloat(l.debit) || 0),
        credit: acc.credit + (parseFloat(l.credit) || 0),
    }), { debit: 0, credit: 0 }), [data.lines]);
    const balanced = Math.abs(journalTotals.debit - journalTotals.credit) < 0.005 && journalTotals.debit > 0;

    const setLine = (i, key, value) => setData('lines', data.lines.map((l, idx) => (idx === i ? { ...l, [key]: value } : l)));
    const ledgerOptions = ledgers.map((l) => <option key={l.id} value={l.id}>{l.code} — {l.name}</option>);

    const submit = (e) => {
        e.preventDefault();
        post(ROUTES[kind], { preserveScroll: true });
    };

    return (
        <AppLayout>
            <div className="mx-auto max-w-3xl space-y-4 p-4">
                <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t(TITLES[kind])}</h1>
                {businessDate && <p className="text-sm text-slate-500">{t('business_date')}: {businessDate}</p>}
                <Card className="p-4">
                    <form onSubmit={submit} className="space-y-4">
                        {kind === 'account' && (
                            <>
                                {setups.length > 0 && (
                                    <Field label={t('acc_receive_payment_setup')}>
                                        <SelectInput value={setupPick} onChange={(e) => setSetupPick(e.target.value)}>
                                            <option value="">{t('select')}</option>
                                            {setups.map((s) => <option key={s.id} value={s.id}>{s.name} ({s.side} · {s.ledger_code})</option>)}
                                        </SelectInput>
                                    </Field>
                                )}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field label={t('acc_mode')} error={errors.side} required>
                                        <SelectInput value={data.side} onChange={(e) => setData('side', e.target.value)}>
                                            <option value="Receive">{t('receive')}</option>
                                            <option value="Payment">{t('payment')}</option>
                                        </SelectInput>
                                    </Field>
                                    <Field label={t('acc_source')} error={errors.source} required>
                                        <SelectInput value={data.source} onChange={(e) => setData('source', e.target.value)}>
                                            <option value="cash">{t('cash')}</option>
                                            <option value="bank">{t('bank')}</option>
                                        </SelectInput>
                                    </Field>
                                </div>
                                <Field label={t('ledger')} error={errors.ledger_id} required>
                                    <SelectInput value={data.ledger_id} onChange={(e) => setData('ledger_id', e.target.value)}>
                                        <option value="">{t('select')}</option>
                                        {ledgerOptions}
                                    </SelectInput>
                                </Field>
                            </>
                        )}

                        {kind === 'ledger' && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label={t('acc_debit_ledger')} error={errors.debit_ledger_id} required>
                                    <SelectInput value={data.debit_ledger_id} onChange={(e) => setData('debit_ledger_id', e.target.value)}>
                                        <option value="">{t('select')}</option>
                                        {ledgerOptions}
                                    </SelectInput>
                                </Field>
                                <Field label={t('acc_credit_ledger')} error={errors.credit_ledger_id} required>
                                    <SelectInput value={data.credit_ledger_id} onChange={(e) => setData('credit_ledger_id', e.target.value)}>
                                        <option value="">{t('select')}</option>
                                        {ledgerOptions}
                                    </SelectInput>
                                </Field>
                            </div>
                        )}

                        {kind === 'contra' && (
                            <Field label={t('acc_direction')} error={errors.direction} required>
                                <SelectInput value={data.direction} onChange={(e) => setData('direction', e.target.value)}>
                                    <option value="cash_to_bank">{t('acc_cash_to_bank')}</option>
                                    <option value="bank_to_cash">{t('acc_bank_to_cash')}</option>
                                </SelectInput>
                            </Field>
                        )}

                        {isJournal ? (
                            <div className="space-y-2">
                                {data.lines.map((line, i) => (
                                    <div key={i} className="grid grid-cols-12 items-end gap-2">
                                        <div className="col-span-12 sm:col-span-6">
                                            <Field label={i === 0 ? t('ledger') : ''} error={errors[`lines.${i}.ledger_id`]}>
                                                <SelectInput value={line.ledger_id} onChange={(e) => setLine(i, 'ledger_id', e.target.value)}>
                                                    <option value="">{t('select')}</option>
                                                    {ledgerOptions}
                                                </SelectInput>
                                            </Field>
                                        </div>
                                        <div className="col-span-5 sm:col-span-2">
                                            <Field label={i === 0 ? t('debit') : ''} error={errors[`lines.${i}.debit`]}>
                                                <TextInput type="number" step="0.01" min="0" value={line.debit} onChange={(e) => setLine(i, 'debit', e.target.value)} />
                                            </Field>
                                        </div>
                                        <div className="col-span-5 sm:col-span-2">
                                            <Field label={i === 0 ? t('credit') : ''} error={errors[`lines.${i}.credit`]}>
                                                <TextInput type="number" step="0.01" min="0" value={line.credit} onChange={(e) => setLine(i, 'credit', e.target.value)} />
                                            </Field>
                                        </div>
                                        <div className="col-span-2">
                                            <button type="button" className="rounded border border-slate-300 px-2 py-2 text-xs text-slate-600 dark:border-slate-600 dark:text-slate-300"
                                                onClick={() => setData('lines', data.lines.filter((_, idx) => idx !== i))} disabled={data.lines.length <= 2}>
                                                {t('remove')}
                                            </button>
                                        </div>
                                    </div>
                                ))}
                                <div className="flex flex-wrap items-center justify-between gap-2 pt-1">
                                    <button type="button" className="rounded border border-slate-300 px-3 py-1.5 text-sm text-slate-600 dark:border-slate-600 dark:text-slate-300"
                                        onClick={() => setData('lines', [...data.lines, { ledger_id: '', debit: '', credit: '' }])}>
                                        {t('acc_add_line')}
                                    </button>
                                    <p className={`text-sm font-medium ${balanced ? 'text-emerald-600' : 'text-rose-600'}`}>
                                        {t('debit')}: {journalTotals.debit.toFixed(2)} · {t('credit')}: {journalTotals.credit.toFixed(2)}
                                        {balanced ? ` ✓ ${t('acc_balanced')}` : ` — ${t('acc_not_balanced')}`}
                                    </p>
                                </div>
                                {errors.lines && <p className="text-sm text-rose-600">{errors.lines}</p>}
                            </div>
                        ) : (
                            <Field label={t('amount')} error={errors.amount} required>
                                <TextInput type="number" step="0.01" min="0.01" value={data.amount} onChange={(e) => setData('amount', e.target.value)} required />
                            </Field>
                        )}

                        <Field label={t('narration')} error={errors.comments}>
                            <TextInput value={data.comments} onChange={(e) => setData('comments', e.target.value)} maxLength={500} />
                        </Field>

                        <PrimaryButton type="submit" loading={processing} disabled={processing || (isJournal && !balanced)}>
                            {t('save')}
                        </PrimaryButton>
                    </form>
                </Card>
            </div>
        </AppLayout>
    );
}
