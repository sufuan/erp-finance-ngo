import { useForm } from '@inertiajs/react';
import { Card, Field, PrimaryButton, SelectInput, TextInput } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

export default function TransferRequest({ branches = [], kind = 'Fund', fromBranch = null, businessDate = null }) {
    const { data, setData, post, processing, errors } = useForm({
        kind, to_branch_id: '', amount: '', item_name: '', quantity: '1', comments: '',
    });
    const isFund = data.kind === 'Fund';

    const submit = (e) => {
        e.preventDefault();
        post('/accounts/transfer-request', { preserveScroll: true });
    };

    return (
        <AppLayout>
            <div className="mx-auto max-w-2xl space-y-4 p-4">
                <h1 className="text-xl font-semibold text-slate-800 dark:text-slate-100">{t(isFund ? 'acc_fund_transfer_request' : 'acc_product_sending_request')}</h1>
                {fromBranch && <p className="text-sm text-slate-500">{t('acc_from_branch')}: {fromBranch.code} — {fromBranch.name} · {businessDate}</p>}
                <Card className="p-4">
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label={t('acc_transfer_kind')} error={errors.kind} required>
                                <SelectInput value={data.kind} onChange={(e) => setData('kind', e.target.value)}>
                                    <option value="Fund">{t('acc_kind_fund')}</option>
                                    <option value="Product">{t('acc_kind_product')}</option>
                                </SelectInput>
                            </Field>
                            <Field label={t('acc_to_branch')} error={errors.to_branch_id} required>
                                <SelectInput value={data.to_branch_id} onChange={(e) => setData('to_branch_id', e.target.value)}>
                                    <option value="">{t('select')}</option>
                                    {branches.filter((b) => b.id !== fromBranch?.id).map((b) => <option key={b.id} value={b.id}>{b.code} — {b.name}</option>)}
                                </SelectInput>
                            </Field>
                        </div>
                        {isFund ? (
                            <Field label={t('amount')} error={errors.amount} required>
                                <TextInput type="number" step="0.01" min="0.01" value={data.amount} onChange={(e) => setData('amount', e.target.value)} required />
                            </Field>
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field label={t('acc_item_name')} error={errors.item_name} required>
                                    <TextInput value={data.item_name} onChange={(e) => setData('item_name', e.target.value)} required />
                                </Field>
                                <Field label={t('acc_quantity')} error={errors.quantity}>
                                    <TextInput type="number" step="0.01" min="0" value={data.quantity} onChange={(e) => setData('quantity', e.target.value)} />
                                </Field>
                                <Field label={t('acc_value_optional')} error={errors.amount}>
                                    <TextInput type="number" step="0.01" min="0" value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                                </Field>
                            </div>
                        )}
                        <Field label={t('narration')} error={errors.comments}>
                            <TextInput value={data.comments} onChange={(e) => setData('comments', e.target.value)} />
                        </Field>
                        <PrimaryButton type="submit" loading={processing}>{t('acc_send_request')}</PrimaryButton>
                    </form>
                </Card>
            </div>
        </AppLayout>
    );
}
