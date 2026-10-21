import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Field, PrimaryButton, ResponsiveTable, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/** Transaction block + block clear (Step 12). mode: 'add' | 'clear'. */
export default function DepositBlocks({ mode, member, memberQuery, accounts, accountId, currentBlock, accountBalance, blocks, businessDate }) {
    const addForm = useForm({
        account_id: accountId ?? '', block_effective_date: businessDate ?? '', block_amount: '',
        reason: '', deposit_block: false, withdrawal_block: true,
    });
    const [clearing, setClearing] = useState(null);
    const clearForm = useForm({ clear_date: businessDate ?? '', clear_amount: '', clear_reason: '', deposit_block: false, withdrawal_block: false });

    const lookup = (e) => {
        e.preventDefault();
        router.get(`/deposits/blocks${mode === 'clear' ? '/clear' : ''}`, { member: e.target.elements.member.value }, { preserveState: true });
    };
    const pickAccount = (id) => router.get(`/deposits/blocks${mode === 'clear' ? '/clear' : ''}`, { member: memberQuery, account_id: id }, { preserveState: true, preserveScroll: true });
    const submitAdd = (e) => { e.preventDefault(); addForm.post('/deposits/blocks', { preserveScroll: true }); };
    const startClear = (b) => { setClearing(b.id); clearForm.setData((prev) => ({ ...prev, clear_amount: b.block_amount })); };
    const submitClear = (e) => { e.preventDefault(); clearForm.post(`/deposits/blocks/${clearing}/clear`, { preserveScroll: true, onSuccess: () => setClearing(null) }); };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t(mode === 'add' ? 'transaction_block' : 'deposit_block_clear')}</h1>
            <div className="mb-4 flex gap-2">
                <button type="button" onClick={() => router.get('/deposits/blocks', { member: memberQuery })} className={`min-h-[44px] rounded-lg px-4 py-2 text-sm font-medium ${mode === 'add' ? 'bg-emerald-600 text-white' : 'border border-zinc-300 dark:border-zinc-700'}`}>{t('transaction_block')}</button>
                <button type="button" onClick={() => router.get('/deposits/blocks/clear', { member: memberQuery })} className={`min-h-[44px] rounded-lg px-4 py-2 text-sm font-medium ${mode === 'clear' ? 'bg-emerald-600 text-white' : 'border border-zinc-300 dark:border-zinc-700'}`}>{t('deposit_block_clear')}</button>
            </div>

            <Card className="mb-4">
                <form onSubmit={lookup} className="flex flex-wrap items-end gap-3">
                    <div className="min-w-56 flex-1">
                        <Field label={`${t('member_no')} *`}>
                            <input name="member" className={inputCls} defaultValue={memberQuery} />
                        </Field>
                    </div>
                    <PrimaryButton type="submit">{t('search')}</PrimaryButton>
                </form>
                {member && (
                    <div className="mt-3 grid gap-3 sm:grid-cols-3">
                        <Field label={t('select_account')}>
                            <select className={inputCls} value={accountId ?? ''} onChange={(e) => pickAccount(e.target.value)}>
                                <option value="">—</option>
                                {accounts.map((a) => <option key={a.id} value={a.id}>{a.account_number} · {a.product} · {a.current_balance}</option>)}
                            </select>
                        </Field>
                        <Field label={t('account_balance')}>
                            <input className={inputCls} value={accountBalance ?? ''} readOnly />
                        </Field>
                        <Field label={t('current_block')}>
                            <input className={inputCls} value={currentBlock ?? ''} readOnly />
                        </Field>
                    </div>
                )}
            </Card>

            {mode === 'add' && member && (
                <Card className="mb-4">
                    <form onSubmit={submitAdd} className="grid gap-4 sm:grid-cols-2">
                        <Field label={`${t('block_effective_date')} *`} error={addForm.errors.block_effective_date}>
                            <input type="date" className={inputCls} value={addForm.data.block_effective_date} onChange={(e) => addForm.setData('block_effective_date', e.target.value)} required />
                        </Field>
                        <Field label={`${t('block_amount')} *`} error={addForm.errors.block_amount}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={addForm.data.block_amount} onChange={(e) => addForm.setData('block_amount', e.target.value)} required />
                        </Field>
                        <Field label={`${t('blocking_reason')} *`} error={addForm.errors.reason}>
                            <input className={inputCls} value={addForm.data.reason} onChange={(e) => addForm.setData('reason', e.target.value)} required />
                        </Field>
                        <div className="flex items-end gap-4">
                            <label className="flex min-h-[44px] items-center gap-2 text-sm">
                                <input type="checkbox" className="h-5 w-5" checked={!!addForm.data.deposit_block} onChange={(e) => addForm.setData('deposit_block', e.target.checked)} />
                                {t('deposit_block')}
                            </label>
                            <label className="flex min-h-[44px] items-center gap-2 text-sm">
                                <input type="checkbox" className="h-5 w-5" checked={!!addForm.data.withdrawal_block} onChange={(e) => addForm.setData('withdrawal_block', e.target.checked)} />
                                {t('withdrawal_block')}
                            </label>
                        </div>
                        {addForm.errors.withdrawal_block && <span className="text-sm text-red-600 dark:text-red-400">{addForm.errors.withdrawal_block}</span>}
                        <div className="sm:col-span-2">
                            <PrimaryButton type="submit" disabled={addForm.processing}>{t('add_block')}</PrimaryButton>
                        </div>
                    </form>
                </Card>
            )}

            {mode === 'clear' && clearing !== null && (
                <Card className="mb-4">
                    <form onSubmit={submitClear} className="grid gap-4 sm:grid-cols-2">
                        <Field label={`${t('remove_date')} *`} error={clearForm.errors.clear_date}>
                            <input type="date" className={inputCls} value={clearForm.data.clear_date} onChange={(e) => clearForm.setData('clear_date', e.target.value)} required />
                        </Field>
                        <Field label={`${t('remove_amount')} *`} error={clearForm.errors.clear_amount}>
                            <input type="number" step="0.01" min="0" className={inputCls} value={clearForm.data.clear_amount} onChange={(e) => clearForm.setData('clear_amount', e.target.value)} required />
                        </Field>
                        <Field label={`${t('clear_reason')} *`} error={clearForm.errors.clear_reason}>
                            <input className={inputCls} value={clearForm.data.clear_reason} onChange={(e) => clearForm.setData('clear_reason', e.target.value)} required />
                        </Field>
                        <div className="flex items-end gap-4">
                            <label className="flex min-h-[44px] items-center gap-2 text-sm">
                                <input type="checkbox" className="h-5 w-5" checked={!!clearForm.data.deposit_block} onChange={(e) => clearForm.setData('deposit_block', e.target.checked)} />
                                {t('deposit_block')}
                            </label>
                            <label className="flex min-h-[44px] items-center gap-2 text-sm">
                                <input type="checkbox" className="h-5 w-5" checked={!!clearForm.data.withdrawal_block} onChange={(e) => clearForm.setData('withdrawal_block', e.target.checked)} />
                                {t('withdrawal_block')}
                            </label>
                        </div>
                        <div className="sm:col-span-2">
                            <PrimaryButton type="submit" disabled={clearForm.processing}>{t('clear_block')}</PrimaryButton>
                        </div>
                    </form>
                </Card>
            )}

            <Card>
                <ResponsiveTable head={[t('branch'), t('member_no'), t('account_number'), t('block_effective_date'), t('block_amount'), t('deposit_block'), t('withdrawal_block'), t('blocking_reason'), t('block_by'), t('actions')]}>
                    {blocks.map((b) => (
                        <tr key={b.id} className="border-b border-zinc-100 dark:border-zinc-800">
                            <td className="px-3 py-2">{b.branch_code ?? '—'}</td>
                            <td className="px-3 py-2">{b.member ?? '—'}</td>
                            <td className="px-3 py-2">{b.account_number}</td>
                            <td className="px-3 py-2">{b.block_date}</td>
                            <td className="px-3 py-2">{b.block_amount}</td>
                            <td className="px-3 py-2">{b.deposit_block ? t('yes') : t('no')}</td>
                            <td className="px-3 py-2">{b.withdrawal_block ? t('yes') : t('no')}</td>
                            <td className="px-3 py-2">{b.reason}</td>
                            <td className="px-3 py-2">{b.block_by ?? '—'} · {b.block_at ? String(b.block_at).slice(0, 10) : ''}</td>
                            <td className="px-3 py-2">
                                {mode === 'clear' && !b.clear_date && (
                                    <button type="button" onClick={() => startClear(b)} className="rounded-lg px-2 py-1 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/20">{t('clear_block')}</button>
                                )}
                                {b.clear_date && <span className="text-zinc-500">{b.clear_date}</span>}
                            </td>
                        </tr>
                    ))}
                    {blocks.length === 0 && <tr><td className="px-3 py-3" colSpan={10}>{t('no_data_found')}</td></tr>}
                </ResponsiveTable>
            </Card>
        </AppLayout>
    );
}
