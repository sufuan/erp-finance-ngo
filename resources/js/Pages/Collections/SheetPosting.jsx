import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CollectionGrid from '../../Components/CollectionGrid';
import { Card, Field, PrimaryButton, inputCls } from '../../Components/ui';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

/**
 * Sheet Posting (Step 21) — the signature collection page: pick a
 * samity, edit the prefilled grid, watch the Summary card, submit.
 */
export default function SheetPosting({ samities, samityId, grid, businessDate }) {
    const { props } = usePage();
    const [searching, setSearching] = useState(false);
    const [selected, setSelected] = useState(samityId ?? '');

    const search = (e) => {
        e.preventDefault();
        router.get('/collection/sheet', { samity_id: selected }, {
            preserveState: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });
    };

    return (
        <AppLayout>
            <h1 className="mb-4 text-xl font-semibold">{t('sheet_posting')}</h1>
            <Card className="mb-4">
                <form onSubmit={search} className="flex flex-wrap items-end gap-3">
                    <div className="min-w-64 flex-1">
                        <Field label={t('samity_name')} required>
                            <select className={inputCls} value={selected} onChange={(e) => setSelected(e.target.value)} required>
                                <option value="">—</option>
                                {samities.map((s) => <option key={s.id} value={s.id}>{s.code} — {s.name}</option>)}
                            </select>
                        </Field>
                    </div>
                    <Field label={t('transaction_date')}>
                        <input type="date" className={inputCls} value={businessDate ?? ''} readOnly />
                    </Field>
                    <PrimaryButton type="submit" loading={searching}>{t('search_details')}</PrimaryButton>
                </form>
            </Card>

            {samityId && (
                <CollectionGrid
                    grid={grid}
                    postUrl={`/collection/sheet?samity_id=${samityId}`}
                    submitLabel={t('post_transaction')}
                    errors={props.errors}
                />
            )}
        </AppLayout>
    );
}
